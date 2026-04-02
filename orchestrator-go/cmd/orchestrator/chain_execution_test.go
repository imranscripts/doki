package main

import (
	"context"
	"encoding/json"
	"io"
	"net/http"
	"slices"
	"strings"
	"sync"
	"testing"

	"doki/orchestrator/internal/controlplane"
	"doki/orchestrator/internal/model"
	"doki/orchestrator/internal/store"
)

type stepEventRecorder struct {
	mu           sync.Mutex
	stepStatuses map[string][]string
}

type roundTripFunc func(*http.Request) (*http.Response, error)

func (fn roundTripFunc) RoundTrip(req *http.Request) (*http.Response, error) {
	return fn(req)
}

func (r *stepEventRecorder) record(stepID, status string) {
	r.mu.Lock()
	defer r.mu.Unlock()
	if r.stepStatuses == nil {
		r.stepStatuses = map[string][]string{}
	}
	r.stepStatuses[stepID] = append(r.stepStatuses[stepID], status)
}

func (r *stepEventRecorder) statuses(stepID string) []string {
	r.mu.Lock()
	defer r.mu.Unlock()
	return append([]string{}, r.stepStatuses[stepID]...)
}

func newEventCaptureClient(t *testing.T) (*controlplane.Client, *stepEventRecorder) {
	t.Helper()

	recorder := &stepEventRecorder{stepStatuses: map[string][]string{}}
	client := controlplane.NewClient("http://controlplane.test", "test-token")
	client.HTTP = &http.Client{
		Transport: roundTripFunc(func(req *http.Request) (*http.Response, error) {
			if req.URL.Path != "/api/internal/orchestrator.php" {
				return &http.Response{
					StatusCode: http.StatusNotFound,
					Body:       io.NopCloser(strings.NewReader(`{"success": false, "error": "not found"}`)),
					Header:     make(http.Header),
					Request:    req,
				}, nil
			}
			if req.URL.Query().Get("action") == "events" {
				var payload struct {
					Events []struct {
						Type    string                 `json:"type"`
						Payload map[string]interface{} `json:"payload"`
					} `json:"events"`
				}
				_ = json.NewDecoder(req.Body).Decode(&payload)
				for _, event := range payload.Events {
					if event.Type != "job.step.update" {
						continue
					}
					stepID, _ := event.Payload["stepId"].(string)
					status, _ := event.Payload["status"].(string)
					recorder.record(stepID, status)
				}
			}

			return &http.Response{
				StatusCode: http.StatusOK,
				Body:       io.NopCloser(strings.NewReader(`{"success": true, "processed": 1, "errors": []}`)),
				Header:     make(http.Header),
				Request:    req,
			}, nil
		}),
	}
	return client, recorder
}

func testIndexedBundle(templates []model.Template, targets []model.Target, environments map[string]model.Environment) *store.IndexedBundle {
	bundle := model.Bundle{
		Templates:    templates,
		Targets:      targets,
		Environments: environments,
	}
	idx := &store.IndexedBundle{
		Bundle:          bundle,
		TemplateByID:    map[string]*model.Template{},
		CommandByID:     map[string]*model.Command{},
		TargetByID:      map[string]*model.Target{},
		EnvironmentByID: map[string]*model.Environment{},
	}

	for i := range idx.Bundle.Templates {
		tpl := &idx.Bundle.Templates[i]
		idx.TemplateByID[tpl.ID] = tpl
	}
	for i := range idx.Bundle.Targets {
		target := &idx.Bundle.Targets[i]
		idx.TargetByID[target.ID] = target
	}
	for id, env := range idx.Bundle.Environments {
		copyEnv := env
		idx.EnvironmentByID[id] = &copyEnv
	}

	return idx
}

func TestBuildCommandChainPlanMergesCommandAndRequestInputs(t *testing.T) {
	bundle := testIndexedBundle([]model.Template{{ID: "tpl-1"}}, nil, nil)
	command := &model.Command{
		ID:            "cmd-1",
		ExecutionMode: "sync",
		Inputs: map[string]interface{}{
			"a": "from-command",
			"b": "from-command",
		},
		Steps: []model.CommandStep{{
			ID:         "step-1",
			TemplateID: "tpl-1",
		}},
	}
	request := model.ExecuteRequest{
		Inputs: map[string]interface{}{
			"b": "from-request",
			"c": "from-request",
		},
	}

	plan, err := buildCommandChainPlan(bundle, command, request, true)
	if err != nil {
		t.Fatalf("buildCommandChainPlan() error = %v", err)
	}

	if got := plan.CommandInputs["a"]; got != "from-command" {
		t.Fatalf("command input a = %v, want from-command", got)
	}
	if got := plan.CommandInputs["b"]; got != "from-request" {
		t.Fatalf("command input b = %v, want from-request", got)
	}
	if got := plan.CommandInputs["c"]; got != "from-request" {
		t.Fatalf("command input c = %v, want from-request", got)
	}
}

func TestBuildCommandChainPlanRejectsReferenceToNonDependencyStep(t *testing.T) {
	bundle := testIndexedBundle([]model.Template{{ID: "tpl-1"}}, nil, nil)
	command := &model.Command{
		ID:            "cmd-2",
		ExecutionMode: "sync",
		Steps: []model.CommandStep{
			{ID: "stepa", TemplateID: "tpl-1"},
			{ID: "stepb", TemplateID: "tpl-1", DependsOn: []string{"stepa"}},
			{ID: "stepc", TemplateID: "tpl-1", DependsOn: []string{"stepa"}, Inputs: map[string]interface{}{"x": "{{steps.stepb.output}}"}},
		},
	}

	_, err := buildCommandChainPlan(bundle, command, model.ExecuteRequest{}, true)
	if err == nil {
		t.Fatal("expected non-dependency interpolation reference error, got nil")
	}
	if got := err.Error(); got == "" || !containsAll(got, "references non-dependency step", "stepb") {
		t.Fatalf("unexpected error: %v", err)
	}
}

func TestBuildCommandChainPlanAllowsHyphenatedDependencyReferences(t *testing.T) {
	bundle := testIndexedBundle([]model.Template{{ID: "tpl-1"}}, nil, nil)
	command := &model.Command{
		ID:            "cmd-hyphen-ref",
		ExecutionMode: "sync",
		Steps: []model.CommandStep{
			{ID: "fetch-char", TemplateID: "tpl-1"},
			{
				ID:         "summary",
				TemplateID: "tpl-1",
				DependsOn:  []string{"fetch-char"},
				Inputs:     map[string]interface{}{"x": "{{steps.fetch-char.output}}"},
			},
		},
	}

	if _, err := buildCommandChainPlan(bundle, command, model.ExecuteRequest{}, true); err != nil {
		t.Fatalf("buildCommandChainPlan() error = %v", err)
	}
}

func TestBuildCommandChainPlanResolvesTargetOrder(t *testing.T) {
	targets := []model.Target{
		{ID: "target-step"},
		{ID: "target-default"},
		{ID: "target-request"},
	}
	bundle := testIndexedBundle([]model.Template{{ID: "tpl-1"}}, targets, nil)

	t.Run("step target wins", func(t *testing.T) {
		command := &model.Command{
			ID:            "cmd-step",
			ExecutionMode: "sync",
			DefaultTarget: model.StepTargetRef{TargetID: "target-default"},
			Steps: []model.CommandStep{{
				ID:         "step-1",
				TemplateID: "tpl-1",
				Target:     model.StepTargetRef{TargetID: "target-step"},
			}},
		}
		plan, err := buildCommandChainPlan(bundle, command, model.ExecuteRequest{TargetID: "target-request"}, true)
		if err != nil {
			t.Fatalf("buildCommandChainPlan() error = %v", err)
		}
		if got := plan.Steps[0].TargetID; got != "target-step" {
			t.Fatalf("step target = %s, want target-step", got)
		}
	})

	t.Run("default target wins over request", func(t *testing.T) {
		command := &model.Command{
			ID:            "cmd-default",
			ExecutionMode: "sync",
			DefaultTarget: model.StepTargetRef{TargetID: "target-default"},
			Steps: []model.CommandStep{{
				ID:         "step-1",
				TemplateID: "tpl-1",
				Target:     model.StepTargetRef{Mode: "inherit"},
			}},
		}
		plan, err := buildCommandChainPlan(bundle, command, model.ExecuteRequest{TargetID: "target-request"}, true)
		if err != nil {
			t.Fatalf("buildCommandChainPlan() error = %v", err)
		}
		if got := plan.Steps[0].TargetID; got != "target-default" {
			t.Fatalf("step target = %s, want target-default", got)
		}
	})

	t.Run("request target used when no step/default target", func(t *testing.T) {
		command := &model.Command{
			ID:            "cmd-request",
			ExecutionMode: "sync",
			DefaultTarget: model.StepTargetRef{Mode: "inherit"},
			Steps: []model.CommandStep{{
				ID:         "step-1",
				TemplateID: "tpl-1",
				Target:     model.StepTargetRef{Mode: "inherit"},
			}},
		}
		plan, err := buildCommandChainPlan(bundle, command, model.ExecuteRequest{TargetID: "target-request"}, true)
		if err != nil {
			t.Fatalf("buildCommandChainPlan() error = %v", err)
		}
		if got := plan.Steps[0].TargetID; got != "target-request" {
			t.Fatalf("step target = %s, want target-request", got)
		}
	})
}

func TestExecuteCommandChainOnFailureStopSkipsDependents(t *testing.T) {
	client, recorder := newEventCaptureClient(t)
	plan := &commandChainPlan{
		Mode:              chainModeSync,
		MaxParallel:       1,
		FinalStatusPolicy: chainFinalPolicyFailIfAny,
		CommandInputs:     map[string]interface{}{},
		Steps: []*commandChainStep{
			{ID: "step-copy", Index: 0, Template: &model.Template{ID: "tpl-1"}, OnFailure: stepOnFailureStop, Inputs: map[string]interface{}{}},
			{ID: "step-check", Index: 1, Template: &model.Template{ID: "tpl-2"}, DependsOn: []string{"step-copy"}, OnFailure: stepOnFailureStop, Inputs: map[string]interface{}{}},
		},
	}

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	result := executeCommandChain(ctx, cancel, client, "job-stop", &model.Command{}, plan, nil, false)

	if result.Status != "failed" {
		t.Fatalf("status = %s, want failed", result.Status)
	}
	if result.ExitCode != 1 {
		t.Fatalf("exit code = %d, want 1", result.ExitCode)
	}
	if !containsAll(result.Error, "Template execution script is empty") {
		t.Fatalf("unexpected error = %q", result.Error)
	}

	stepCheckStatuses := recorder.statuses("step-check")
	if !slices.Contains(stepCheckStatuses, "skipped") {
		t.Fatalf("step-check statuses = %v, expected skipped", stepCheckStatuses)
	}
	if slices.Contains(stepCheckStatuses, "running") {
		t.Fatalf("step-check statuses = %v, did not expect running", stepCheckStatuses)
	}
}

func TestExecuteCommandChainOnFailureContinueRunsDependents(t *testing.T) {
	client, recorder := newEventCaptureClient(t)
	plan := &commandChainPlan{
		Mode:              chainModeSync,
		MaxParallel:       1,
		FinalStatusPolicy: chainFinalPolicyFailIfAny,
		CommandInputs:     map[string]interface{}{},
		Steps: []*commandChainStep{
			{ID: "step-copy", Index: 0, Template: &model.Template{ID: "tpl-1"}, OnFailure: stepOnFailureContinue, Inputs: map[string]interface{}{}},
			{ID: "step-check", Index: 1, Template: &model.Template{ID: "tpl-2"}, DependsOn: []string{"step-copy"}, OnFailure: stepOnFailureStop, Inputs: map[string]interface{}{}},
		},
	}

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	result := executeCommandChain(ctx, cancel, client, "job-continue", &model.Command{}, plan, nil, false)

	if result.Status != "failed" {
		t.Fatalf("status = %s, want failed", result.Status)
	}

	stepCheckStatuses := recorder.statuses("step-check")
	if !slices.Contains(stepCheckStatuses, "failed") {
		t.Fatalf("step-check statuses = %v, expected failed", stepCheckStatuses)
	}
}

func TestExecuteCommandChainCompletedWithWarningsPolicy(t *testing.T) {
	plan := &commandChainPlan{
		Mode:              chainModeSync,
		MaxParallel:       1,
		FinalStatusPolicy: chainFinalPolicyCompletedWithWarnings,
		CommandInputs:     map[string]interface{}{},
		Steps: []*commandChainStep{
			{ID: "step-1", Index: 0, Template: &model.Template{ID: "tpl-1"}, OnFailure: stepOnFailureContinue, Inputs: map[string]interface{}{}},
			{ID: "step-2", Index: 1, Template: &model.Template{ID: "tpl-2"}, DependsOn: []string{"step-1"}, OnFailure: stepOnFailureContinue, Inputs: map[string]interface{}{}},
		},
	}

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	result := executeCommandChain(ctx, cancel, nil, "job-warn", &model.Command{}, plan, nil, false)

	if result.Status != "completed_with_warnings" {
		t.Fatalf("status = %s, want completed_with_warnings", result.Status)
	}
	if result.ExitCode != 0 {
		t.Fatalf("exit code = %d, want 0", result.ExitCode)
	}
}

func TestExecuteCommandChainHonorsCancellation(t *testing.T) {
	client, recorder := newEventCaptureClient(t)
	plan := &commandChainPlan{
		Mode:              chainModeSync,
		MaxParallel:       1,
		FinalStatusPolicy: chainFinalPolicyFailIfAny,
		CommandInputs:     map[string]interface{}{},
		Steps: []*commandChainStep{
			{ID: "step-1", Index: 0, Template: &model.Template{ID: "tpl-1"}, OnFailure: stepOnFailureStop, Inputs: map[string]interface{}{}},
			{ID: "step-2", Index: 1, Template: &model.Template{ID: "tpl-2"}, OnFailure: stepOnFailureStop, Inputs: map[string]interface{}{}},
		},
	}

	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	result := executeCommandChain(ctx, cancel, client, "job-cancel", &model.Command{}, plan, nil, false)

	if result.Status != "cancelled" {
		t.Fatalf("status = %s, want cancelled", result.Status)
	}
	if result.ExitCode != 130 {
		t.Fatalf("exit code = %d, want 130", result.ExitCode)
	}

	for _, stepID := range []string{"step-1", "step-2"} {
		statuses := recorder.statuses(stepID)
		if !slices.Contains(statuses, "cancelled") {
			t.Fatalf("step %s statuses = %v, expected cancelled", stepID, statuses)
		}
	}
}

func TestExecuteCommandChainDryRunBuildsRedactedPreview(t *testing.T) {
	plan := &commandChainPlan{
		Mode:              chainModeSync,
		MaxParallel:       1,
		FinalStatusPolicy: chainFinalPolicyFailIfAny,
		CommandInputs:     map[string]interface{}{},
		Steps: []*commandChainStep{
			{
				ID:    "step-preview",
				Index: 0,
				Template: &model.Template{
					ID: "tpl-preview",
					Runtime: model.RuntimeConfig{
						Image:   "alpine:latest",
						Workdir: "/workspace",
						Network: "host",
					},
					Execution: model.ExecutionConfig{
						Mode:   "sync",
						Script: `echo "$API_TOKEN"`,
					},
				},
				OnFailure: stepOnFailureStop,
				Inputs:    map[string]interface{}{},
				StepSecrets: model.SecretMap{
					"API_TOKEN": "api-token",
				},
			},
		},
	}

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	result := executeCommandChain(ctx, cancel, nil, "job-dry-run", &model.Command{}, plan, map[string]string{
		"api-token": "[secret:api-token]",
	}, true)

	if result.Status != "completed" {
		t.Fatalf("status = %q, want completed", result.Status)
	}
	if result.ExitCode != 0 {
		t.Fatalf("exitCode = %d, want 0", result.ExitCode)
	}
	if !containsAll(result.Output, "Dry run: no commands were executed.", "API_TOKEN=[secret:api-token]") {
		t.Fatalf("dry-run output missing expected content: %s", result.Output)
	}
}

func TestExtractStepValues(t *testing.T) {
	template := &model.Template{
		ID: "tpl-extract",
		Output: map[string]interface{}{
			"extractors": []interface{}{
				map[string]interface{}{"name": "artifact", "pattern": `artifact=(\w+)`},
				map[string]interface{}{"name": "version", "pattern": `version:(\d+\.\d+\.\d+)`},
			},
		},
	}

	values := extractStepValues(template, "artifact=release\nversion:1.2.3")
	if values["artifact"] != "release" {
		t.Fatalf("artifact = %v, want release", values["artifact"])
	}
	if values["version"] != "1.2.3" {
		t.Fatalf("version = %v, want 1.2.3", values["version"])
	}
}

func containsAll(s string, parts ...string) bool {
	for _, part := range parts {
		if part == "" {
			continue
		}
		if !strings.Contains(s, part) {
			return false
		}
	}
	return true
}
