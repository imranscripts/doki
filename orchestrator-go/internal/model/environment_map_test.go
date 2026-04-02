package model

import (
	"encoding/json"
	"testing"
)

func TestConfigResponseUnmarshalAcceptsEmptyEnvironmentArray(t *testing.T) {
	raw := []byte(`{
		"success": true,
		"bundle": {
			"templates": [],
			"commands": [],
			"targets": [],
			"environments": [],
			"generatedAt": "2026-03-28T00:00:00Z"
		}
	}`)

	var resp ConfigResponse
	if err := json.Unmarshal(raw, &resp); err != nil {
		t.Fatalf("json.Unmarshal() error = %v", err)
	}

	if resp.Bundle.Environments == nil {
		t.Fatal("Bundle.Environments is nil, want empty map")
	}
	if len(resp.Bundle.Environments) != 0 {
		t.Fatalf("len(Bundle.Environments) = %d, want 0", len(resp.Bundle.Environments))
	}
}

func TestConfigResponseUnmarshalAcceptsEnvironmentTargetsArray(t *testing.T) {
	raw := []byte(`{
		"success": true,
		"bundle": {
			"templates": [],
			"commands": [],
			"targets": [],
			"environments": {
				"prod": {
					"name": "Production",
					"targets": []
				}
			},
			"generatedAt": "2026-03-28T00:00:00Z"
		}
	}`)

	var resp ConfigResponse
	if err := json.Unmarshal(raw, &resp); err != nil {
		t.Fatalf("json.Unmarshal() error = %v", err)
	}

	env, ok := resp.Bundle.Environments["prod"]
	if !ok {
		t.Fatal(`Bundle.Environments["prod"] missing`)
	}
	if env.Targets == nil {
		t.Fatal("env.Targets is nil, want empty map")
	}
	if len(env.Targets) != 0 {
		t.Fatalf("len(env.Targets) = %d, want 0", len(env.Targets))
	}
}
