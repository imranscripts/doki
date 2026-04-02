package main

import (
	"context"
	"fmt"
	"log"
	"net/http"
	"regexp"
	"sort"
	"strings"

	"doki/orchestrator/internal/controlplane"
	"doki/orchestrator/internal/model"
	"doki/orchestrator/internal/orchestrator"
	"doki/orchestrator/internal/store"
)

const (
	chainModeSync  = "sync"
	chainModeAsync = "async"

	chainFinalPolicyFailIfAny             = "fail_if_any_failed"
	chainFinalPolicyCompletedWithWarnings = "completed_with_warnings"

	stepOnFailureStop     = "stop"
	stepOnFailureContinue = "continue"
)

var (
	chainIfRefRegex          = regexp.MustCompile(`\{\{#if ([a-zA-Z0-9_.-]+)\}\}`)
	chainPlaceholderRefRegex = regexp.MustCompile(`\{\{([a-zA-Z0-9_.-]+)\}\}`)
)

type commandChainStep struct {
	ID          string
	Index       int
	Template    *model.Template
	Target      *model.Target
	TargetID    string
	DependsOn   []string
	OnFailure   string
	Inputs      map[string]interface{}
	StepSecrets model.SecretMap
}

type commandChainPlan struct {
	Mode              string
	MaxParallel       int
	FinalStatusPolicy string
	CommandInputs     map[string]interface{}
	Steps             []*commandChainStep
	Snapshot          map[string]interface{}
}

type commandChainExecutionResult struct {
	Status   string
	ExitCode int
	Error    string
	Output   string
}

type chainStepState struct {
	Status   string
	ExitCode int
	Error    string
	Output   string
	Extract  map[string]interface{}
}

type chainStepRunResult struct {
	StepID    string
	StepIndex int
	Status    string
	ExitCode  int
	Error     string
	Output    string
	Extract   map[string]interface{}
}

func handleExecuteCommandChain(ctx context.Context, bundle *store.IndexedBundle, client *controlplane.Client, asyncJobs *orchestrator.AsyncJobRegistry, command *model.Command, req model.ExecuteRequest) (model.ExecuteResponse, int) {
	plan, err := buildCommandChainPlan(bundle, command, req, !req.DryRun)
	if err != nil {
		return model.ExecuteResponse{Success: false, Error: err.Error()}, http.StatusBadRequest
	}

	secretIDs := collectChainSecretIDs(command, plan.Steps)
	secretValues := map[string]string{}
	if req.DryRun {
		secretValues = orchestrator.BuildSecretPlaceholders(secretIDs)
	} else if len(secretIDs) > 0 {
		resolution, err := orchestrator.ResolveSecrets(ctx, client, secretIDs)
		if err != nil {
			if resolution.Locked {
				return model.ExecuteResponse{Success: false, Error: "Secrets are locked"}, http.StatusLocked
			}
			return model.ExecuteResponse{Success: false, Error: "Failed to resolve secrets: " + err.Error()}, http.StatusBadGateway
		}
		secretValues = resolution.Values
	}

	jobID := orchestrator.GenerateJobID()
	if client != nil && req.User != nil {
		payload := map[string]interface{}{
			"userId":       req.User["id"],
			"username":     req.User["username"],
			"action":       auditAction(command),
			"resourceType": auditResourceType(command),
			"resourceId":   auditResourceID(command, nil),
			"details":      auditDetails(req, jobID),
			"ip":           req.Request["ip"],
		}
		if err := orchestrator.EmitAuditEvent(ctx, client, payload); err != nil {
			log.Printf("audit event failed: %v", err)
		}
	}

	jobCreateErr := error(nil)
	if client != nil {
		createStatus := "running"
		if plan.Mode == chainModeAsync {
			createStatus = "pending"
		}
		if err := orchestrator.EmitJobCreateWithPlan(ctx, client, jobID, command, nil, nil, plan.CommandInputs, req.User, createStatus, plan.Snapshot, req.DryRun); err != nil {
			jobCreateErr = err
			log.Printf("job create event failed: %v", err)
		}
	}

	if req.DryRun {
		runCtx, cancel := context.WithCancel(ctx)
		defer cancel()
		result := executeCommandChain(runCtx, cancel, client, jobID, command, plan, secretValues, true)
		emitJobOutputWithTimeout(client, jobID, result.Output)
		emitJobUpdateWithTimeout(client, jobID, result.Status, result.ExitCode, result.Error)

		return model.ExecuteResponse{
			Success:  true,
			JobID:    jobID,
			Status:   result.Status,
			Output:   result.Output,
			ExitCode: result.ExitCode,
			DryRun:   true,
			Error:    result.Error,
		}, http.StatusOK
	}

	if plan.Mode == chainModeAsync {
		if jobCreateErr != nil {
			return model.ExecuteResponse{Success: false, Error: "Failed to create async job record"}, http.StatusBadGateway
		}

		runCtx, cancel := context.WithCancel(context.Background())
		if asyncJobs != nil {
			asyncJobs.Register(jobID, cancel)
		}
		go runAsyncChainExecution(runCtx, cancel, client, asyncJobs, jobID, command, plan, secretValues)
		return model.ExecuteResponse{
			Success: true,
			JobID:   jobID,
			Status:  "pending",
		}, http.StatusOK
	}

	runCtx, cancel := context.WithCancel(ctx)
	defer cancel()
	result := executeCommandChain(runCtx, cancel, client, jobID, command, plan, secretValues, false)
	emitJobOutputWithTimeout(client, jobID, result.Output)
	emitJobUpdateWithTimeout(client, jobID, result.Status, result.ExitCode, result.Error)

	return model.ExecuteResponse{
		Success:  true,
		JobID:    jobID,
		Status:   result.Status,
		Output:   result.Output,
		ExitCode: result.ExitCode,
		Error:    result.Error,
	}, http.StatusOK
}

func runAsyncChainExecution(runCtx context.Context, cancel context.CancelFunc, client *controlplane.Client, asyncJobs *orchestrator.AsyncJobRegistry, jobID string, command *model.Command, plan *commandChainPlan, secretValues map[string]string) {
	defer cancel()
	if asyncJobs != nil {
		defer asyncJobs.Done(jobID)
	}

	if runCtx.Err() == context.Canceled {
		emitJobUpdateWithTimeout(client, jobID, "cancelled", 130, "Cancelled")
		return
	}

	emitJobUpdateWithTimeout(client, jobID, "running", -1, "")
	result := executeCommandChain(runCtx, cancel, client, jobID, command, plan, secretValues, false)
	emitJobOutputWithTimeout(client, jobID, result.Output)
	emitJobUpdateWithTimeout(client, jobID, result.Status, result.ExitCode, result.Error)
}

func buildCommandChainPlan(bundle *store.IndexedBundle, command *model.Command, req model.ExecuteRequest, requireVerifiedTargets bool) (*commandChainPlan, error) {
	if command == nil {
		return nil, fmt.Errorf("command is required")
	}
	if len(command.Steps) == 0 {
		return nil, fmt.Errorf("legacy single-template commands are not supported; command must define steps")
	}

	mode := normalizeChainMode(command.ExecutionMode)
	if mode == "" {
		return nil, fmt.Errorf("command execution mode is required")
	}

	maxParallel := command.MaxParallel
	if maxParallel <= 0 {
		maxParallel = 1
	}

	finalPolicy := normalizeFinalStatusPolicy(command.FinalStatusPolicy)
	if finalPolicy == "" {
		finalPolicy = chainFinalPolicyFailIfAny
	}

	commandInputs := map[string]interface{}{}
	for key, value := range command.Inputs {
		commandInputs[key] = value
	}
	for key, value := range req.Inputs {
		commandInputs[key] = value
	}

	steps := make([]*commandChainStep, 0, len(command.Steps))
	stepByID := map[string]*commandChainStep{}

	for i := range command.Steps {
		rawStep := command.Steps[i]
		stepID := strings.TrimSpace(rawStep.ID)
		if stepID == "" {
			return nil, fmt.Errorf("step id is required at index %d", i)
		}
		if _, exists := stepByID[stepID]; exists {
			return nil, fmt.Errorf("duplicate step id: %s", stepID)
		}

		templateID := strings.TrimSpace(rawStep.TemplateID)
		if templateID == "" {
			return nil, fmt.Errorf("step %s: templateId is required", stepID)
		}
		template := bundle.TemplateByID[templateID]
		if template == nil {
			return nil, fmt.Errorf("step %s: template not found: %s", stepID, templateID)
		}

		dependsOn := normalizeStepDependencies(command.Steps, i)
		onFailure := normalizeStepOnFailure(rawStep.OnFailure)
		if onFailure == "" {
			return nil, fmt.Errorf("step %s: invalid onFailure value", stepID)
		}

		target, targetID, err := resolveStepTarget(bundle, command, rawStep, template, req.TargetID)
		if err != nil {
			return nil, fmt.Errorf("step %s: %w", stepID, err)
		}
		if requireVerifiedTargets {
			if err := orchestrator.AssertTargetVerified(target); err != nil {
				return nil, fmt.Errorf("step %s: %w", stepID, err)
			}
		}

		step := &commandChainStep{
			ID:          stepID,
			Index:       i,
			Template:    template,
			Target:      target,
			TargetID:    targetID,
			DependsOn:   dependsOn,
			OnFailure:   onFailure,
			Inputs:      copyMap(rawStep.Inputs),
			StepSecrets: copySecrets(rawStep.Secrets),
		}
		steps = append(steps, step)
		stepByID[stepID] = step
	}

	for _, step := range steps {
		for _, dep := range step.DependsOn {
			if dep == step.ID {
				return nil, fmt.Errorf("step %s cannot depend on itself", step.ID)
			}
			if _, ok := stepByID[dep]; !ok {
				return nil, fmt.Errorf("step %s has unknown dependency: %s", step.ID, dep)
			}
		}
	}
	if !isAcyclicChain(steps) {
		return nil, fmt.Errorf("step dependency graph contains a cycle")
	}

	reachable := computeDependencyClosure(steps)
	for _, step := range steps {
		references := collectInterpolationReferences(step.Inputs)
		for _, ref := range references {
			if !strings.HasPrefix(ref, "steps.") {
				continue
			}
			parts := strings.Split(ref, ".")
			if len(parts) < 3 {
				return nil, fmt.Errorf("step %s has invalid step reference: %s", step.ID, ref)
			}
			refStepID := strings.TrimSpace(parts[1])
			if refStepID == "" {
				return nil, fmt.Errorf("step %s has invalid step reference: %s", step.ID, ref)
			}
			if !reachable[step.ID][refStepID] {
				return nil, fmt.Errorf("step %s references non-dependency step: %s", step.ID, refStepID)
			}
		}
	}

	snapshotSteps := make([]map[string]interface{}, 0, len(steps))
	for _, step := range steps {
		snapshotSteps = append(snapshotSteps, map[string]interface{}{
			"id":         step.ID,
			"index":      step.Index,
			"templateId": step.Template.ID,
			"targetId":   step.TargetID,
			"dependsOn":  step.DependsOn,
			"onFailure":  step.OnFailure,
			"inputs":     step.Inputs,
		})
	}
	snapshot := map[string]interface{}{
		"executionMode":     mode,
		"maxParallel":       maxParallel,
		"finalStatusPolicy": finalPolicy,
		"steps":             snapshotSteps,
	}

	return &commandChainPlan{
		Mode:              mode,
		MaxParallel:       maxParallel,
		FinalStatusPolicy: finalPolicy,
		CommandInputs:     commandInputs,
		Steps:             steps,
		Snapshot:          snapshot,
	}, nil
}

func executeCommandChain(runCtx context.Context, cancel context.CancelFunc, client *controlplane.Client, jobID string, command *model.Command, plan *commandChainPlan, secretValues map[string]string, dryRun bool) commandChainExecutionResult {
	if plan == nil || len(plan.Steps) == 0 {
		return commandChainExecutionResult{Status: "failed", ExitCode: 1, Error: "chain plan is empty"}
	}

	stepByID := map[string]*commandChainStep{}
	dependents := map[string][]string{}
	remainingDeps := map[string]int{}
	for _, step := range plan.Steps {
		stepByID[step.ID] = step
		remainingDeps[step.ID] = len(step.DependsOn)
		for _, dep := range step.DependsOn {
			dependents[dep] = append(dependents[dep], step.ID)
		}
		emitJobStepCreateWithTimeout(client, jobID, step.ID, step.Index, step.Template, step.Target, "pending", step.OnFailure, step.DependsOn, step.Inputs, dryRun)
	}

	ready := make([]string, 0, len(plan.Steps))
	for _, step := range plan.Steps {
		if remainingDeps[step.ID] == 0 {
			ready = append(ready, step.ID)
		}
	}
	sortReadyQueue(ready, stepByID)

	stepState := map[string]chainStepState{}
	resultsCh := make(chan chainStepRunResult, len(plan.Steps))
	running := 0
	completed := 0
	anyFailed := false
	firstFailureExitCode := 1
	firstFailureError := ""
	stopMode := false
	stopCause := ""
	var output strings.Builder

	for completed < len(plan.Steps) {
		if runCtx.Err() == context.Canceled && stopCause == "" {
			stopMode = true
			stopCause = "cancelled"
		}

		for !stopMode && runCtx.Err() == nil && running < plan.MaxParallel && len(ready) > 0 {
			stepID := ready[0]
			ready = ready[1:]

			step := stepByID[stepID]
			if step == nil {
				continue
			}
			if _, exists := stepState[step.ID]; exists {
				continue
			}

			interpCtx := buildInterpolationContext(plan.CommandInputs, stepState)
			resolvedStepInputs, err := interpolateInputs(step.Inputs, interpCtx)
			if err != nil {
				running++
				go func(id string, index int, err error) {
					resultsCh <- chainStepRunResult{
						StepID:    id,
						StepIndex: index,
						Status:    "failed",
						ExitCode:  1,
						Error:     "Failed to interpolate step inputs: " + err.Error(),
					}
				}(step.ID, step.Index, err)
				continue
			}

			mergedStepInputs := mergeStepInputs(plan.CommandInputs, resolvedStepInputs)
			validationErrors := model.ValidateInputs(step.Template, mergedStepInputs)
			if len(validationErrors) > 0 {
				running++
				go func(id string, index int, firstError string) {
					resultsCh <- chainStepRunResult{
						StepID:    id,
						StepIndex: index,
						Status:    "failed",
						ExitCode:  1,
						Error:     "Validation failed: " + firstError,
					}
				}(step.ID, step.Index, validationErrors[0])
				continue
			}

			emitJobStepUpdateWithTimeout(client, jobID, step.ID, step.Index, "running", -1, "")
			running++
			go func(step *commandChainStep, mergedStepInputs map[string]interface{}, interpCtx map[string]interface{}) {
				resultsCh <- runChainStep(runCtx, jobID, command, step, mergedStepInputs, interpCtx, secretValues, dryRun)
			}(step, mergedStepInputs, interpCtx)
		}

		if running == 0 {
			break
		}

		result := <-resultsCh
		running--
		completed++

		if result.Output != "" {
			if output.Len() > 0 {
				output.WriteString("\n\n")
			}
			output.WriteString("=== STEP ")
			output.WriteString(result.StepID)
			output.WriteString(" ===\n")
			output.WriteString(result.Output)
		}

		stepState[result.StepID] = chainStepState{
			Status:   result.Status,
			ExitCode: result.ExitCode,
			Error:    result.Error,
			Output:   result.Output,
			Extract:  result.Extract,
		}

		emitJobStepOutputWithTimeout(client, jobID, result.StepID, result.Output)
		emitJobStepUpdateWithTimeout(client, jobID, result.StepID, result.StepIndex, result.Status, result.ExitCode, result.Error)

		if result.Status == "failed" {
			anyFailed = true
			if firstFailureError == "" {
				firstFailureError = result.Error
				firstFailureExitCode = result.ExitCode
				if firstFailureExitCode == 0 {
					firstFailureExitCode = 1
				}
			}

			if step := stepByID[result.StepID]; step != nil && step.OnFailure == stepOnFailureStop {
				stopMode = true
				stopCause = "failure_stop"
				if cancel != nil {
					cancel()
				}
			}
		}
		if result.Status == "cancelled" && stopCause == "" {
			stopMode = true
			stopCause = "cancelled"
		}

		for _, depID := range dependents[result.StepID] {
			remainingDeps[depID]--
			if remainingDeps[depID] <= 0 {
				ready = append(ready, depID)
			}
		}
		sortReadyQueue(ready, stepByID)
	}

	for _, step := range plan.Steps {
		if _, ok := stepState[step.ID]; ok {
			continue
		}
		status := "skipped"
		exitCode := 0
		errMsg := ""
		if stopCause == "cancelled" || runCtx.Err() == context.Canceled && stopCause != "failure_stop" {
			status = "cancelled"
			exitCode = 130
			errMsg = "Cancelled"
		}
		stepState[step.ID] = chainStepState{
			Status:   status,
			ExitCode: exitCode,
			Error:    errMsg,
			Extract:  map[string]interface{}{},
		}
		emitJobStepUpdateWithTimeout(client, jobID, step.ID, step.Index, status, exitCode, errMsg)
	}

	finalStatus := "completed"
	finalExitCode := 0
	finalError := ""

	if stopCause == "cancelled" || runCtx.Err() == context.Canceled && stopCause != "failure_stop" {
		finalStatus = "cancelled"
		finalExitCode = 130
		finalError = "Cancelled"
	} else if anyFailed {
		if plan.FinalStatusPolicy == chainFinalPolicyCompletedWithWarnings {
			finalStatus = "completed_with_warnings"
			finalExitCode = 0
			finalError = firstFailureError
		} else {
			finalStatus = "failed"
			finalExitCode = firstFailureExitCode
			finalError = firstFailureError
		}
	}

	return commandChainExecutionResult{
		Status:   finalStatus,
		ExitCode: finalExitCode,
		Error:    finalError,
		Output:   output.String(),
	}
}

func runChainStep(ctx context.Context, jobID string, command *model.Command, step *commandChainStep, inputs map[string]interface{}, interpolationCtx map[string]interface{}, secretValues map[string]string, dryRun bool) chainStepRunResult {
	result := chainStepRunResult{
		StepID:    step.ID,
		StepIndex: step.Index,
		Extract:   map[string]interface{}{},
	}

	if step == nil || step.Template == nil {
		result.Status = "failed"
		result.ExitCode = 1
		result.Error = "Step template is required"
		return result
	}

	bindings := orchestrator.ResolveTargetBindings(step.Template, step.Target)
	scriptVars := map[string]interface{}{
		"inputs": inputs,
		"steps":  interpolationCtx["steps"],
	}
	if commandInputs, ok := interpolationCtx["inputs"]; ok {
		scriptVars["commandInputs"] = commandInputs
	}
	for key, value := range inputs {
		scriptVars[key] = value
	}
	for key, value := range bindings {
		scriptVars[key] = value
	}

	script := strings.TrimSpace(step.Template.Execution.Script)
	if script == "" {
		script = strings.TrimSpace(step.Template.Execution.Command)
	}
	if script == "" {
		result.Status = "failed"
		result.ExitCode = 1
		result.Error = "Template execution script is empty"
		return result
	}
	script = model.InterpolateScript(script, scriptVars)

	stepCommand := &model.Command{
		Secrets: mergeSecrets(command, step),
	}

	envResult := orchestrator.BuildEnvVars(step.Template, step.Target, inputs, bindings, stepCommand, secretValues)
	execSpec := orchestrator.ExecSpec{
		JobID:    jobID,
		Template: step.Template,
		Target:   step.Target,
		Script:   script,
		Env:      envResult.Env,
	}

	execResult := orchestrator.ExecResult{}
	if dryRun {
		execResult = orchestrator.BuildDryRunExecResult(orchestrator.CommandPreviewSpec{
			JobID:        jobID,
			Template:     step.Template,
			Target:       step.Target,
			Script:       script,
			Env:          envResult.Env,
			SecretEnvMap: envResult.SecretEnvMap,
			SecretValues: secretValues,
		})
	} else {
		execResult = orchestrator.ExecuteSync(ctx, execSpec)
	}

	canceled := ctx.Err() == context.Canceled
	status, exitCode, errMsg := classifyExecutionResult(execResult, canceled)

	result.Status = status
	result.ExitCode = exitCode
	result.Error = errMsg
	result.Output = execResult.Output
	result.Extract = extractStepValues(step.Template, execResult.Output)
	return result
}

func normalizeChainMode(mode string) string {
	mode = strings.ToLower(strings.TrimSpace(mode))
	switch mode {
	case chainModeSync, chainModeAsync:
		return mode
	default:
		return ""
	}
}

func normalizeFinalStatusPolicy(policy string) string {
	policy = strings.ToLower(strings.TrimSpace(policy))
	switch policy {
	case "", chainFinalPolicyFailIfAny:
		return chainFinalPolicyFailIfAny
	case chainFinalPolicyCompletedWithWarnings:
		return chainFinalPolicyCompletedWithWarnings
	default:
		return ""
	}
}

func normalizeStepOnFailure(value string) string {
	value = strings.ToLower(strings.TrimSpace(value))
	switch value {
	case "", stepOnFailureStop:
		return stepOnFailureStop
	case stepOnFailureContinue:
		return stepOnFailureContinue
	default:
		return ""
	}
}

func normalizeStepDependencies(all []model.CommandStep, index int) []string {
	if index < 0 || index >= len(all) {
		return []string{}
	}

	raw := all[index].DependsOn
	if len(raw) == 0 && index > 0 {
		previous := strings.TrimSpace(all[index-1].ID)
		if previous != "" {
			return []string{previous}
		}
	}

	seen := map[string]struct{}{}
	result := make([]string, 0, len(raw))
	for _, dep := range raw {
		dep = strings.TrimSpace(dep)
		if dep == "" {
			continue
		}
		if _, ok := seen[dep]; ok {
			continue
		}
		seen[dep] = struct{}{}
		result = append(result, dep)
	}
	return result
}

func resolveStepTarget(bundle *store.IndexedBundle, command *model.Command, step model.CommandStep, template *model.Template, requestTargetID string) (*model.Target, string, error) {
	candidates := []string{}

	if id := resolveTargetIDFromRef(bundle, command, step.Target, template); id != "" {
		candidates = append(candidates, id)
	}
	if id := resolveTargetIDFromRef(bundle, command, command.DefaultTarget, template); id != "" {
		candidates = append(candidates, id)
	}
	if raw := strings.TrimSpace(requestTargetID); raw != "" {
		candidates = append(candidates, raw)
	}

	for _, targetID := range candidates {
		targetID = strings.TrimSpace(targetID)
		if targetID == "" {
			continue
		}
		target := bundle.TargetByID[targetID]
		if target == nil {
			return nil, "", fmt.Errorf("target not found: %s", targetID)
		}
		return target, targetID, nil
	}

	return nil, "", nil
}

func resolveTargetIDFromRef(bundle *store.IndexedBundle, command *model.Command, ref model.StepTargetRef, template *model.Template) string {
	switch ref.NormalizedMode() {
	case "targetid":
		return strings.TrimSpace(ref.TargetID)
	case "fromenvironment":
		return resolveFromEnvironmentRef(bundle, command, strings.TrimSpace(ref.FromEnvironment), template)
	default:
		return ""
	}
}

func resolveFromEnvironmentRef(bundle *store.IndexedBundle, command *model.Command, fromEnvironment string, template *model.Template) string {
	fromEnvironment = strings.TrimSpace(fromEnvironment)
	if fromEnvironment == "" {
		return ""
	}
	if bundle.TargetByID[fromEnvironment] != nil {
		return fromEnvironment
	}

	targetType := ""
	if template != nil && template.TargetType != nil {
		targetType = strings.TrimSpace(*template.TargetType)
	}

	if command != nil && command.Environment != "" {
		env := bundle.EnvironmentByID[command.Environment]
		if env != nil {
			if targetID := strings.TrimSpace(env.Targets[fromEnvironment]); targetID != "" {
				return targetID
			}
			if fromEnvironment == "default" {
				if targetID := strings.TrimSpace(env.Targets["default"]); targetID != "" {
					return targetID
				}
			}
			if targetID := model.ResolveTargetFromEnvironment(*env, targetType, bundle.Bundle.Targets); targetID != "" {
				return targetID
			}
		}
	}

	if env := bundle.EnvironmentByID[fromEnvironment]; env != nil {
		if targetID := model.ResolveTargetFromEnvironment(*env, targetType, bundle.Bundle.Targets); targetID != "" {
			return targetID
		}
	}
	return ""
}

func isAcyclicChain(steps []*commandChainStep) bool {
	remaining := map[string]int{}
	dependents := map[string][]string{}
	for _, step := range steps {
		remaining[step.ID] = len(step.DependsOn)
		for _, dep := range step.DependsOn {
			dependents[dep] = append(dependents[dep], step.ID)
		}
	}

	queue := make([]string, 0, len(steps))
	for _, step := range steps {
		if remaining[step.ID] == 0 {
			queue = append(queue, step.ID)
		}
	}

	visited := 0
	for len(queue) > 0 {
		id := queue[0]
		queue = queue[1:]
		visited++
		for _, dep := range dependents[id] {
			remaining[dep]--
			if remaining[dep] == 0 {
				queue = append(queue, dep)
			}
		}
	}

	return visited == len(steps)
}

func computeDependencyClosure(steps []*commandChainStep) map[string]map[string]bool {
	direct := map[string][]string{}
	for _, step := range steps {
		direct[step.ID] = append([]string{}, step.DependsOn...)
	}

	cache := map[string]map[string]bool{}
	var visit func(string) map[string]bool
	visit = func(stepID string) map[string]bool {
		if existing, ok := cache[stepID]; ok {
			return existing
		}
		result := map[string]bool{}
		cache[stepID] = result
		for _, dep := range direct[stepID] {
			result[dep] = true
			for transitive := range visit(dep) {
				result[transitive] = true
			}
		}
		return result
	}

	for stepID := range direct {
		visit(stepID)
	}
	return cache
}

func collectInterpolationReferences(value interface{}) []string {
	seen := map[string]struct{}{}
	var walk func(interface{})
	walk = func(v interface{}) {
		switch typed := v.(type) {
		case string:
			for _, match := range chainIfRefRegex.FindAllStringSubmatch(typed, -1) {
				if len(match) == 2 {
					ref := strings.TrimSpace(match[1])
					if ref != "" {
						seen[ref] = struct{}{}
					}
				}
			}
			for _, match := range chainPlaceholderRefRegex.FindAllStringSubmatch(typed, -1) {
				if len(match) == 2 {
					ref := strings.TrimSpace(match[1])
					if ref != "" {
						seen[ref] = struct{}{}
					}
				}
			}
		case map[string]interface{}:
			for _, item := range typed {
				walk(item)
			}
		case []interface{}:
			for _, item := range typed {
				walk(item)
			}
		}
	}

	walk(value)
	refs := make([]string, 0, len(seen))
	for ref := range seen {
		refs = append(refs, ref)
	}
	sort.Strings(refs)
	return refs
}

func interpolateInputs(inputs map[string]interface{}, interpolationCtx map[string]interface{}) (map[string]interface{}, error) {
	if inputs == nil {
		return map[string]interface{}{}, nil
	}
	interpolated, err := interpolateValue(inputs, interpolationCtx)
	if err != nil {
		return nil, err
	}
	parsed, ok := interpolated.(map[string]interface{})
	if !ok {
		return nil, fmt.Errorf("step inputs must resolve to an object")
	}
	return parsed, nil
}

func interpolateValue(value interface{}, interpolationCtx map[string]interface{}) (interface{}, error) {
	switch typed := value.(type) {
	case string:
		return model.InterpolateScript(typed, interpolationCtx), nil
	case map[string]interface{}:
		result := map[string]interface{}{}
		for key, item := range typed {
			next, err := interpolateValue(item, interpolationCtx)
			if err != nil {
				return nil, err
			}
			result[key] = next
		}
		return result, nil
	case []interface{}:
		result := make([]interface{}, 0, len(typed))
		for _, item := range typed {
			next, err := interpolateValue(item, interpolationCtx)
			if err != nil {
				return nil, err
			}
			result = append(result, next)
		}
		return result, nil
	default:
		return value, nil
	}
}

func buildInterpolationContext(commandInputs map[string]interface{}, stepState map[string]chainStepState) map[string]interface{} {
	steps := map[string]interface{}{}
	for stepID, state := range stepState {
		steps[stepID] = map[string]interface{}{
			"status":   state.Status,
			"exitCode": state.ExitCode,
			"error":    state.Error,
			"output":   state.Output,
			"extract":  state.Extract,
		}
	}
	return map[string]interface{}{
		"inputs": commandInputs,
		"steps":  steps,
	}
}

func sortReadyQueue(ready []string, stepByID map[string]*commandChainStep) {
	sort.SliceStable(ready, func(i, j int) bool {
		left := stepByID[ready[i]]
		right := stepByID[ready[j]]
		if left == nil || right == nil {
			return ready[i] < ready[j]
		}
		if left.Index == right.Index {
			return left.ID < right.ID
		}
		return left.Index < right.Index
	})
}

func collectChainSecretIDs(command *model.Command, steps []*commandChainStep) []string {
	seen := map[string]struct{}{}
	for _, step := range steps {
		stepCommand := &model.Command{
			Secrets: mergeSecrets(command, step),
		}
		for _, secretID := range orchestrator.CollectSecretIDs(stepCommand, step.Target) {
			if secretID == "" {
				continue
			}
			seen[secretID] = struct{}{}
		}
	}
	ids := make([]string, 0, len(seen))
	for id := range seen {
		ids = append(ids, id)
	}
	sort.Strings(ids)
	return ids
}

func mergeSecrets(command *model.Command, step *commandChainStep) model.SecretMap {
	result := model.SecretMap{}
	if command != nil {
		for key, value := range command.Secrets {
			result[key] = value
		}
	}
	if step != nil {
		for key, value := range step.StepSecrets {
			result[key] = value
		}
	}
	return result
}

func copyMap(input map[string]interface{}) map[string]interface{} {
	if input == nil {
		return map[string]interface{}{}
	}
	result := make(map[string]interface{}, len(input))
	for key, value := range input {
		result[key] = value
	}
	return result
}

func copySecrets(input model.SecretMap) model.SecretMap {
	if input == nil {
		return model.SecretMap{}
	}
	result := model.SecretMap{}
	for key, value := range input {
		result[key] = value
	}
	return result
}

func mergeStepInputs(commandInputs map[string]interface{}, stepInputs map[string]interface{}) map[string]interface{} {
	result := copyMap(commandInputs)
	for key, value := range stepInputs {
		result[key] = value
	}
	return result
}

func extractStepValues(template *model.Template, output string) map[string]interface{} {
	values := map[string]interface{}{}
	if template == nil || len(template.Output) == 0 || output == "" {
		return values
	}

	rawExtractors, ok := template.Output["extractors"]
	if !ok || rawExtractors == nil {
		return values
	}

	extractors, ok := rawExtractors.([]interface{})
	if !ok {
		return values
	}

	for _, raw := range extractors {
		entry, ok := raw.(map[string]interface{})
		if !ok {
			continue
		}
		name, _ := entry["name"].(string)
		pattern, _ := entry["pattern"].(string)
		name = strings.TrimSpace(name)
		pattern = strings.TrimSpace(pattern)
		if name == "" || pattern == "" {
			continue
		}

		compiled, err := regexp.Compile(pattern)
		if err != nil {
			continue
		}
		match := compiled.FindStringSubmatch(output)
		if len(match) == 0 {
			continue
		}
		if len(match) > 1 {
			values[name] = match[1]
		} else {
			values[name] = match[0]
		}
	}
	return values
}
