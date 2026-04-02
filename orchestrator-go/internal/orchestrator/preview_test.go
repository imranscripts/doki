package orchestrator

import (
	"strings"
	"testing"

	"doki/orchestrator/internal/model"
)

func TestBuildCommandPreviewRedactsSecretValues(t *testing.T) {
	template := &model.Template{
		ID: "tpl-preview",
		Runtime: model.RuntimeConfig{
			Image:   "alpine:latest",
			Workdir: "/workspace",
			Network: "host",
		},
	}

	preview := BuildCommandPreview(CommandPreviewSpec{
		JobID:    "job-preview",
		Template: template,
		Script:   `echo "$API_TOKEN"`,
		Env: []string{
			"API_TOKEN=super-secret-token",
			"INPUT_BRANCH=main",
		},
		SecretEnvMap: map[string]string{
			"API_TOKEN": "api-token",
		},
		SecretValues: map[string]string{
			"api-token": "super-secret-token",
		},
	})

	if strings.Contains(preview, "super-secret-token") {
		t.Fatalf("preview leaked a secret value: %s", preview)
	}
	if !strings.Contains(preview, "API_TOKEN=[secret:api-token]") {
		t.Fatalf("preview did not include the redacted secret placeholder: %s", preview)
	}
	if !strings.Contains(preview, "Dry run: no commands were executed.") {
		t.Fatalf("preview missing dry-run header: %s", preview)
	}
}
