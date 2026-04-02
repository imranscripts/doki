package main

import (
	"context"
	"io"
	"net/http"
	"strings"
	"testing"
	"time"

	"doki/orchestrator/internal/controlplane"
	"doki/orchestrator/internal/model"
	"doki/orchestrator/internal/orchestrator"
	"doki/orchestrator/internal/store"
)

func TestClassifyExecutionResult(t *testing.T) {
	tests := []struct {
		name           string
		result         orchestrator.ExecResult
		canceled       bool
		wantStatus     string
		wantExitCode   int
		wantErrMessage string
	}{
		{
			name: "completed",
			result: orchestrator.ExecResult{
				ExitCode: 0,
			},
			wantStatus:     "completed",
			wantExitCode:   0,
			wantErrMessage: "",
		},
		{
			name: "failed_non_zero",
			result: orchestrator.ExecResult{
				ExitCode: 2,
			},
			wantStatus:     "failed",
			wantExitCode:   2,
			wantErrMessage: "",
		},
		{
			name: "failed_error_message",
			result: orchestrator.ExecResult{
				ExitCode: 0,
				Error:    "boom",
			},
			wantStatus:     "failed",
			wantExitCode:   0,
			wantErrMessage: "boom",
		},
		{
			name: "cancelled_by_context",
			result: orchestrator.ExecResult{
				ExitCode: 0,
			},
			canceled:       true,
			wantStatus:     "cancelled",
			wantExitCode:   130,
			wantErrMessage: "Cancelled",
		},
		{
			name: "cancelled_by_result_error",
			result: orchestrator.ExecResult{
				ExitCode: 130,
				Error:    "canceled",
			},
			wantStatus:     "cancelled",
			wantExitCode:   130,
			wantErrMessage: "canceled",
		},
	}

	for _, tc := range tests {
		tc := tc
		t.Run(tc.name, func(t *testing.T) {
			status, exitCode, errMessage := classifyExecutionResult(tc.result, tc.canceled)
			if status != tc.wantStatus {
				t.Fatalf("status = %q, want %q", status, tc.wantStatus)
			}
			if exitCode != tc.wantExitCode {
				t.Fatalf("exitCode = %d, want %d", exitCode, tc.wantExitCode)
			}
			if errMessage != tc.wantErrMessage {
				t.Fatalf("errMessage = %q, want %q", errMessage, tc.wantErrMessage)
			}
		})
	}
}

func TestHandleExecuteDryRunSupportsCommandDraft(t *testing.T) {
	client := controlplane.NewClient("http://controlplane.test", "test-token")
	client.HTTP = &http.Client{
		Transport: roundTripFunc(func(req *http.Request) (*http.Response, error) {
			if req.URL.Path == "/api/internal/orchestrator.php" && req.URL.Query().Get("action") == "config" {
				body := `{"success":true,"bundle":{"templates":[{"id":"shell-exec","name":"Shell Exec","runtime":{"image":"alpine:3.19","workdir":"","network":"","resources":{}},"execution":{"mode":"sync","script":"{{command}}","command":"","timeout":30},"inputs":[],"output":{},"requirements":[],"targetBindings":[]}],"commands":[],"targets":[],"environments":{},"generatedAt":"2026-03-28T00:00:00Z"}}`
				return &http.Response{
					StatusCode: http.StatusOK,
					Body:       io.NopCloser(strings.NewReader(body)),
					Header:     make(http.Header),
					Request:    req,
				}, nil
			}

			return &http.Response{
				StatusCode: http.StatusOK,
				Body:       io.NopCloser(strings.NewReader(`{"success":true,"processed":1,"errors":[]}`)),
				Header:     make(http.Header),
				Request:    req,
			}, nil
		}),
	}

	configStore := store.NewConfigStore(client, time.Hour)
	req := model.ExecuteRequest{
		CommandDraft: &model.Command{
			ID:            "draft-deploy",
			Name:          "Draft Deploy",
			ExecutionMode: "sync",
			DefaultTarget: model.StepTargetRef{Mode: "inherit"},
			Steps: []model.CommandStep{
				{
					ID:         "step-1",
					TemplateID: "shell-exec",
					Target:     model.StepTargetRef{Mode: "inherit"},
					Inputs: map[string]interface{}{
						"command": "echo hello from draft",
					},
				},
			},
		},
		DryRun: true,
	}

	resp, status := handleExecute(context.Background(), configStore, nil, nil, req)
	if status != http.StatusOK {
		t.Fatalf("status = %d, want %d (resp=%+v)", status, http.StatusOK, resp)
	}
	if !resp.Success {
		t.Fatalf("resp.Success = false, error = %q", resp.Error)
	}
	if !resp.DryRun {
		t.Fatalf("resp.DryRun = false, want true")
	}
	if resp.JobID == "" {
		t.Fatal("resp.JobID is empty")
	}
	if !strings.Contains(resp.Output, "echo hello from draft") {
		t.Fatalf("resp.Output = %q, want dry-run preview with draft command", resp.Output)
	}
}
