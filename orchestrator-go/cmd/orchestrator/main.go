package main

import (
	"context"
	"encoding/json"
	"io"
	"log"
	"net/http"
	"os"
	"strconv"
	"strings"
	"time"

	"doki/orchestrator/internal/controlplane"
	"doki/orchestrator/internal/model"
	"doki/orchestrator/internal/orchestrator"
	"doki/orchestrator/internal/playwright"
	"doki/orchestrator/internal/store"
)

type Config struct {
	Addr              string
	ControlPlaneURL   string
	ControlPlaneToken string
	AppDir            string
	HostAppDir        string
}

type ErrorResponse struct {
	Success bool   `json:"success"`
	Error   string `json:"error"`
}

type HealthResponse struct {
	OK bool `json:"ok"`
}

func loadConfig() Config {
	addr := getEnv("ORCH_LISTEN_ADDR", ":8098")
	controlPlaneURL := getEnv("ORCH_CONTROL_PLANE_URL", "http://php-app")
	controlPlaneToken := getEnv("DOKI_ORCH_TOKEN", "")
	appDir := getEnv("ORCH_APP_DIR", "/var/www/html")
	hostAppDir := getEnv("HOST_APP_PATH", "")
	return Config{
		Addr:              addr,
		ControlPlaneURL:   strings.TrimRight(controlPlaneURL, "/"),
		ControlPlaneToken: controlPlaneToken,
		AppDir:            strings.TrimRight(appDir, "/"),
		HostAppDir:        strings.TrimRight(hostAppDir, "/"),
	}
}

func getEnv(key, fallback string) string {
	value := strings.TrimSpace(os.Getenv(key))
	if value == "" {
		return fallback
	}
	return value
}

func writeJSON(w http.ResponseWriter, status int, payload interface{}) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(payload)
}

func truncateLogString(value string, max int) string {
	if max <= 0 || len(value) <= max {
		return value
	}
	return value[:max] + "...(truncated)"
}

func emitJobOutputWithTimeout(client *controlplane.Client, jobID string, output string) {
	if client == nil {
		return
	}

	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	if err := orchestrator.EmitJobOutput(ctx, client, jobID, output); err != nil {
		log.Printf("job output event failed: %v", err)
	}
}

func emitJobUpdateWithTimeout(client *controlplane.Client, jobID string, status string, exitCode int, errMsg string) {
	if client == nil {
		return
	}

	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	if err := orchestrator.EmitJobUpdate(ctx, client, jobID, status, exitCode, errMsg); err != nil {
		log.Printf("job update event failed: %v", err)
	}
}

func emitJobStepCreateWithTimeout(client *controlplane.Client, jobID string, stepID string, stepIndex int, template *model.Template, target *model.Target, status string, onFailure string, dependsOn []string, inputs map[string]interface{}, dryRun bool) {
	if client == nil {
		return
	}
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	if err := orchestrator.EmitJobStepCreate(ctx, client, jobID, stepID, stepIndex, template, target, status, onFailure, dependsOn, inputs, dryRun); err != nil {
		log.Printf("job step create event failed: %v", err)
	}
}

func emitJobStepOutputWithTimeout(client *controlplane.Client, jobID string, stepID string, output string) {
	if client == nil {
		return
	}
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	if err := orchestrator.EmitJobStepOutput(ctx, client, jobID, stepID, output); err != nil {
		log.Printf("job step output event failed: %v", err)
	}
}

func emitJobStepUpdateWithTimeout(client *controlplane.Client, jobID string, stepID string, stepIndex int, status string, exitCode int, errMsg string) {
	if client == nil {
		return
	}
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	if err := orchestrator.EmitJobStepUpdate(ctx, client, jobID, stepID, stepIndex, status, exitCode, errMsg); err != nil {
		log.Printf("job step update event failed: %v", err)
	}
}

func classifyExecutionResult(result orchestrator.ExecResult, canceled bool) (string, int, string) {
	status := "completed"
	exitCode := result.ExitCode
	errMsg := result.Error

	if canceled || strings.EqualFold(strings.TrimSpace(result.Error), "cancelled") || strings.EqualFold(strings.TrimSpace(result.Error), "canceled") {
		status = "cancelled"
		if exitCode == 0 {
			exitCode = 130
		}
		if errMsg == "" {
			errMsg = "Cancelled"
		}
		return status, exitCode, errMsg
	}

	if exitCode != 0 || errMsg != "" {
		status = "failed"
	}
	return status, exitCode, errMsg
}

func runAsyncExecution(runCtx context.Context, cancel context.CancelFunc, client *controlplane.Client, asyncJobs *orchestrator.AsyncJobRegistry, jobID string, spec orchestrator.ExecSpec) {
	defer cancel()
	if asyncJobs != nil {
		defer asyncJobs.Done(jobID)
	}

	// Handle cancellation requested before the worker started.
	if runCtx.Err() == context.Canceled {
		emitJobUpdateWithTimeout(client, jobID, "cancelled", 130, "Cancelled")
		return
	}

	emitJobUpdateWithTimeout(client, jobID, "running", -1, "")

	result := orchestrator.ExecuteSync(runCtx, spec)
	canceled := runCtx.Err() == context.Canceled

	status, exitCode, errMsg := classifyExecutionResult(result, canceled)
	emitJobOutputWithTimeout(client, jobID, result.Output)
	emitJobUpdateWithTimeout(client, jobID, status, exitCode, errMsg)
}

func handleExecute(ctx context.Context, configStore *store.ConfigStore, client *controlplane.Client, asyncJobs *orchestrator.AsyncJobRegistry, req model.ExecuteRequest) (model.ExecuteResponse, int) {
	bundle, err := configStore.GetBundle(ctx)
	if err != nil {
		return model.ExecuteResponse{Success: false, Error: "Failed to load configuration: " + err.Error()}, http.StatusBadGateway
	}

	command := (*model.Command)(nil)
	if req.CommandID != "" && req.CommandDraft != nil {
		return model.ExecuteResponse{Success: false, Error: "commandDraft cannot be used with commandId"}, http.StatusBadRequest
	}
	if req.CommandID != "" {
		command = bundle.CommandByID[req.CommandID]
		if command == nil {
			return model.ExecuteResponse{Success: false, Error: "Command not found: " + req.CommandID}, http.StatusBadRequest
		}
	}
	if req.CommandDraft != nil {
		command = req.CommandDraft
	}
	if command != nil && req.TemplateID != "" {
		return model.ExecuteResponse{Success: false, Error: "templateId cannot be used with commandId"}, http.StatusBadRequest
	}
	if command != nil {
		return handleExecuteCommandChain(ctx, bundle, client, asyncJobs, command, req)
	}

	templateID := req.TemplateID
	if templateID == "" {
		return model.ExecuteResponse{Success: false, Error: "Template ID required"}, http.StatusBadRequest
	}

	template := bundle.TemplateByID[templateID]
	if template == nil {
		return model.ExecuteResponse{Success: false, Error: "Template not found: " + templateID}, http.StatusBadRequest
	}

	mode := strings.ToLower(strings.TrimSpace(template.Execution.Mode))
	if mode == "" {
		return model.ExecuteResponse{Success: false, Error: "Template execution mode is required"}, http.StatusBadRequest
	}
	if mode != "sync" && mode != "async" {
		return model.ExecuteResponse{Success: false, Error: "Unsupported execution mode: " + mode}, http.StatusBadRequest
	}

	targetID := req.TargetID
	if targetID == "" && command != nil {
		targetID = command.TargetID
	}

	if targetID == "" && command != nil && command.Environment != "" {
		env := bundle.EnvironmentByID[command.Environment]
		if env != nil {
			targetType := ""
			if template.TargetType != nil {
				targetType = *template.TargetType
			}
			targetID = model.ResolveTargetFromEnvironment(*env, targetType, bundle.Bundle.Targets)
		}
	}

	var target *model.Target
	if targetID != "" {
		target = bundle.TargetByID[targetID]
		if target == nil {
			return model.ExecuteResponse{Success: false, Error: "Target not found: " + targetID}, http.StatusBadRequest
		}
	}

	if !req.DryRun {
		if err := orchestrator.AssertTargetVerified(target); err != nil {
			return model.ExecuteResponse{Success: false, Error: err.Error()}, http.StatusForbidden
		}
	}

	mergedInputs := map[string]interface{}{}
	if command != nil && command.Inputs != nil {
		for key, value := range command.Inputs {
			mergedInputs[key] = value
		}
	}
	if req.Inputs != nil {
		for key, value := range req.Inputs {
			mergedInputs[key] = value
		}
	}

	bindings := orchestrator.ResolveTargetBindings(template, target)
	interpolationVars := map[string]interface{}{}
	for key, value := range mergedInputs {
		interpolationVars[key] = value
	}
	for key, value := range bindings {
		interpolationVars[key] = value
	}

	validationErrors := model.ValidateInputs(template, mergedInputs)
	if len(validationErrors) > 0 {
		errorMessage := "Validation failed"
		if len(validationErrors) > 0 {
			errorMessage = errorMessage + ": " + validationErrors[0]
		}
		return model.ExecuteResponse{
			Success: false,
			Error:   errorMessage,
			Errors:  validationErrors,
		}, http.StatusBadRequest
	}

	script := template.Execution.Script
	if script == "" {
		script = template.Execution.Command
	}
	if script == "" {
		return model.ExecuteResponse{Success: false, Error: "Template execution script is empty"}, http.StatusBadRequest
	}
	script = model.InterpolateScript(script, interpolationVars)

	secretIDs := orchestrator.CollectSecretIDs(command, target)
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
	envResult := orchestrator.BuildEnvVars(template, target, mergedInputs, bindings, command, secretValues)

	if client != nil && req.User != nil {
		payload := map[string]interface{}{
			"userId":       req.User["id"],
			"username":     req.User["username"],
			"action":       auditAction(command),
			"resourceType": auditResourceType(command),
			"resourceId":   auditResourceID(command, template),
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
		if mode == "async" {
			createStatus = "pending"
		}
		if err := orchestrator.EmitJobCreate(ctx, client, jobID, command, template, target, mergedInputs, req.User, createStatus, req.DryRun); err != nil {
			jobCreateErr = err
			log.Printf("job create event failed: %v", err)
		}
	}

	spec := orchestrator.ExecSpec{
		JobID:    jobID,
		Template: template,
		Target:   target,
		Script:   script,
		Env:      envResult.Env,
	}

	if req.DryRun {
		result := orchestrator.BuildDryRunExecResult(orchestrator.CommandPreviewSpec{
			JobID:        jobID,
			Template:     template,
			Target:       target,
			Script:       script,
			Env:          envResult.Env,
			SecretEnvMap: envResult.SecretEnvMap,
			SecretValues: secretValues,
		})

		emitJobOutputWithTimeout(client, jobID, result.Output)
		emitJobUpdateWithTimeout(client, jobID, "completed", 0, "")

		return model.ExecuteResponse{
			Success:  true,
			JobID:    jobID,
			Status:   "completed",
			Output:   result.Output,
			ExitCode: 0,
			DryRun:   true,
		}, http.StatusOK
	}

	if mode == "async" {
		// Async jobs must be persisted first, otherwise they cannot be polled.
		if jobCreateErr != nil {
			return model.ExecuteResponse{Success: false, Error: "Failed to create async job record"}, http.StatusBadGateway
		}

		runCtx, cancel := context.WithCancel(context.Background())
		if asyncJobs != nil {
			asyncJobs.Register(jobID, cancel)
		}
		go runAsyncExecution(runCtx, cancel, client, asyncJobs, jobID, spec)

		return model.ExecuteResponse{
			Success: true,
			JobID:   jobID,
			Status:  "pending",
		}, http.StatusOK
	}

	result := orchestrator.ExecuteSync(ctx, spec)
	status, exitCode, errMsg := classifyExecutionResult(result, false)

	emitJobOutputWithTimeout(client, jobID, result.Output)
	emitJobUpdateWithTimeout(client, jobID, status, exitCode, errMsg)

	return model.ExecuteResponse{
		Success:  true,
		JobID:    jobID,
		Status:   status,
		Output:   result.Output,
		ExitCode: exitCode,
		DryRun:   req.DryRun,
		Error:    errMsg,
	}, http.StatusOK
}

func auditAction(command *model.Command) string {
	if command != nil {
		return "command.execute"
	}
	return "template.execute"
}

func auditResourceType(command *model.Command) string {
	if command != nil {
		return "command"
	}
	return "template"
}

func auditResourceID(command *model.Command, template *model.Template) string {
	if command != nil {
		return command.ID
	}
	if template != nil {
		return template.ID
	}
	return ""
}

func auditDetails(req model.ExecuteRequest, jobID string) string {
	payload := map[string]interface{}{
		"goPreflight": true,
	}
	if jobID != "" {
		payload["jobId"] = jobID
	}
	if req.CommandID != "" {
		payload["commandId"] = req.CommandID
	}
	if req.CommandDraft != nil {
		payload["commandDraftId"] = req.CommandDraft.ID
		payload["commandDraft"] = true
	}
	if req.TemplateID != "" {
		payload["templateId"] = req.TemplateID
	}
	if req.TargetID != "" {
		payload["targetId"] = req.TargetID
	}
	if req.DryRun {
		payload["dryRun"] = true
	}
	if req.Inputs != nil {
		payload["inputs"] = req.Inputs
	}
	raw, _ := json.Marshal(payload)
	return string(raw)
}

func main() {
	cfg := loadConfig()
	client := controlplane.NewClient(cfg.ControlPlaneURL, cfg.ControlPlaneToken)
	configStore := store.NewConfigStore(client, 30*time.Second)
	asyncJobs := orchestrator.NewAsyncJobRegistry()
	playwrightManager := playwright.NewManager(cfg.AppDir, cfg.HostAppDir, client, configStore)

	mux := http.NewServeMux()

	mux.HandleFunc("/health", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodGet {
			writeJSON(w, http.StatusMethodNotAllowed, ErrorResponse{Success: false, Error: "GET required"})
			return
		}
		writeJSON(w, http.StatusOK, HealthResponse{OK: true})
	})

	mux.HandleFunc("/config/reload", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			writeJSON(w, http.StatusMethodNotAllowed, ErrorResponse{Success: false, Error: "POST required"})
			return
		}
		configStore.Invalidate()
		log.Printf("config cache invalidated via /config/reload from %s", r.RemoteAddr)
		writeJSON(w, http.StatusOK, map[string]interface{}{"success": true})
	})

	mux.HandleFunc("/execute", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			writeJSON(w, http.StatusMethodNotAllowed, ErrorResponse{Success: false, Error: "POST required"})
			return
		}

		var req model.ExecuteRequest
		rawBody, readErr := io.ReadAll(r.Body)
		if readErr != nil {
			log.Printf("execute request body read failed: err=%v remote=%s contentType=%q contentLength=%d", readErr, r.RemoteAddr, r.Header.Get("Content-Type"), r.ContentLength)
			writeJSON(w, http.StatusBadRequest, ErrorResponse{Success: false, Error: "Invalid JSON"})
			return
		}
		if err := json.Unmarshal(rawBody, &req); err != nil {
			log.Printf(
				"execute decode failed: err=%v remote=%s contentType=%q contentLength=%d rawSnippet=%q",
				err,
				r.RemoteAddr,
				r.Header.Get("Content-Type"),
				r.ContentLength,
				truncateLogString(string(rawBody), 1000),
			)
			writeJSON(w, http.StatusBadRequest, ErrorResponse{Success: false, Error: "Invalid JSON"})
			return
		}
		log.Printf(
			"execute request decoded: commandId=%q hasCommandDraft=%t commandDraftId=%q templateId=%q targetId=%q dryRun=%t inputsKeys=%d userKeys=%d requestKeys=%d remote=%s",
			req.CommandID,
			req.CommandDraft != nil,
			func() string {
				if req.CommandDraft == nil {
					return ""
				}
				return req.CommandDraft.ID
			}(),
			req.TemplateID,
			req.TargetID,
			req.DryRun,
			len(req.Inputs),
			len(req.User),
			len(req.Request),
			r.RemoteAddr,
		)

		result, status := handleExecute(r.Context(), configStore, client, asyncJobs, req)
		writeJSON(w, status, result)
	})

	mux.HandleFunc("/job/", func(w http.ResponseWriter, r *http.Request) {
		if !strings.HasSuffix(r.URL.Path, "/cancel") && r.Method != http.MethodGet {
			writeJSON(w, http.StatusMethodNotAllowed, ErrorResponse{Success: false, Error: "GET required"})
			return
		}
		if strings.HasSuffix(r.URL.Path, "/cancel") {
			if r.Method != http.MethodPost {
				writeJSON(w, http.StatusMethodNotAllowed, ErrorResponse{Success: false, Error: "POST required"})
				return
			}
			jobID := strings.TrimSuffix(strings.TrimPrefix(r.URL.Path, "/job/"), "/cancel")
			asyncCanceled := asyncJobs.RequestCancel(jobID)
			dockerResult := orchestrator.CancelJob(jobID)

			resp := orchestrator.CancelResponse{}
			if asyncCanceled || dockerResult.Success {
				resp.Success = true
			} else {
				resp.Success = false
				resp.Error = dockerResult.Error
			}

			status := http.StatusOK
			if !resp.Success {
				status = http.StatusBadRequest
			}
			writeJSON(w, status, resp)
			return
		}

		jobID := strings.TrimPrefix(r.URL.Path, "/job/")
		ctx, cancel := context.WithTimeout(r.Context(), 10*time.Second)
		defer cancel()

		job, err := client.FetchJob(ctx, jobID)
		if err != nil {
			writeJSON(w, http.StatusNotFound, ErrorResponse{Success: false, Error: err.Error()})
			return
		}
		writeJSON(w, http.StatusOK, map[string]interface{}{"success": true, "job": job})
	})

	mux.HandleFunc("/jobs", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodGet {
			writeJSON(w, http.StatusMethodNotAllowed, ErrorResponse{Success: false, Error: "GET required"})
			return
		}
		query := r.URL.Query()
		userID := query.Get("userId")
		limit := 0
		if raw := query.Get("limit"); raw != "" {
			if parsed, err := strconv.Atoi(raw); err == nil {
				limit = parsed
			}
		}

		ctx, cancel := context.WithTimeout(r.Context(), 10*time.Second)
		defer cancel()

		jobs, err := client.FetchJobs(ctx, userID, limit)
		if err != nil {
			writeJSON(w, http.StatusBadGateway, ErrorResponse{Success: false, Error: err.Error()})
			return
		}
		writeJSON(w, http.StatusOK, map[string]interface{}{"success": true, "jobs": jobs})
	})

	mux.HandleFunc("/targets/", func(w http.ResponseWriter, r *http.Request) {
		if !strings.HasSuffix(r.URL.Path, "/test") {
			writeJSON(w, http.StatusNotFound, ErrorResponse{Success: false, Error: "Not found"})
			return
		}
		if r.Method != http.MethodPost {
			writeJSON(w, http.StatusMethodNotAllowed, ErrorResponse{Success: false, Error: "POST required"})
			return
		}
		writeJSON(w, http.StatusNotImplemented, ErrorResponse{Success: false, Error: "Target test not implemented"})
	})

	mux.HandleFunc("/playwright/run", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			writeJSON(w, http.StatusMethodNotAllowed, ErrorResponse{Success: false, Error: "POST required"})
			return
		}

		var req playwright.RunRequest
		if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
			writeJSON(w, http.StatusBadRequest, ErrorResponse{Success: false, Error: "Invalid JSON"})
			return
		}

		ctx, cancel := context.WithTimeout(r.Context(), 15*time.Second)
		defer cancel()

		resp, status := playwrightManager.StartRun(ctx, req)
		writeJSON(w, status, resp)
	})

	mux.HandleFunc("/playwright/job/", func(w http.ResponseWriter, r *http.Request) {
		if strings.HasSuffix(r.URL.Path, "/cancel") {
			if r.Method != http.MethodPost {
				writeJSON(w, http.StatusMethodNotAllowed, ErrorResponse{Success: false, Error: "POST required"})
				return
			}
			jobID := strings.TrimSuffix(strings.TrimPrefix(r.URL.Path, "/playwright/job/"), "/cancel")
			ctx, cancel := context.WithTimeout(r.Context(), 10*time.Second)
			defer cancel()
			resp, status := playwrightManager.CancelJob(ctx, jobID)
			writeJSON(w, status, resp)
			return
		}

		if r.Method != http.MethodGet {
			writeJSON(w, http.StatusMethodNotAllowed, ErrorResponse{Success: false, Error: "GET required"})
			return
		}

		jobID := strings.TrimPrefix(r.URL.Path, "/playwright/job/")
		ctx, cancel := context.WithTimeout(r.Context(), 10*time.Second)
		defer cancel()
		resp, status := playwrightManager.GetJobStatus(ctx, jobID)
		writeJSON(w, status, resp)
	})

	mux.HandleFunc("/playwright/reports/cleanup", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			writeJSON(w, http.StatusMethodNotAllowed, ErrorResponse{Success: false, Error: "POST required"})
			return
		}
		var req playwright.CleanupRequest
		if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
			req = playwright.CleanupRequest{}
		}

		resp, status := playwrightManager.CleanupReports(req)
		writeJSON(w, status, resp)
	})

	server := &http.Server{
		Addr:              cfg.Addr,
		Handler:           mux,
		ReadHeaderTimeout: 10 * time.Second,
		ReadTimeout:       30 * time.Second,
		WriteTimeout:      30 * time.Second,
		IdleTimeout:       60 * time.Second,
	}

	log.Printf("Go orchestrator listening on %s", cfg.Addr)
	log.Printf("Control plane URL: %s", cfg.ControlPlaneURL)
	log.Printf("App dir: %s", cfg.AppDir)
	if cfg.HostAppDir != "" {
		log.Printf("Host app dir: %s", cfg.HostAppDir)
	}
	if err := server.ListenAndServe(); err != nil && err != http.ErrServerClosed {
		log.Fatalf("server error: %v", err)
	}
}
