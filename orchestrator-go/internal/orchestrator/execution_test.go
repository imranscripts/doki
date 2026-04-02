package orchestrator

import (
	"slices"
	"testing"

	"doki/orchestrator/internal/model"
)

func TestBuildDockerRunArgsIncludesExpandedTemplateVolumes(t *testing.T) {
	spec := ExecSpec{
		JobID: "job-volumes",
		Template: &model.Template{
			ID: "git-diff-risk-scan",
			Runtime: model.RuntimeConfig{
				Image:   "alpine/git:latest",
				Workdir: "/workspace/repo",
				Volumes: []model.RuntimeVolume{
					{
						Host:      "${INPUT_REPOPATH}",
						Container: "/workspace/repo",
						Readonly:  true,
					},
				},
			},
		},
		Script: "git status --short",
		Env: []string{
			"INPUT_REPOPATH=/Users/imr/Documents/GitHub/doki",
		},
	}

	args := buildDockerRunArgs(spec, "alpine/git:latest")
	if !slices.Contains(args, "/Users/imr/Documents/GitHub/doki:/workspace/repo:ro") {
		t.Fatalf("expected expanded repo mount in docker args, got %v", args)
	}
}

func TestExpandRuntimeValueLeavesUnknownVariablesUntouched(t *testing.T) {
	got := expandRuntimeValue("${INPUT_REPOPATH}/state/${UNKNOWN}", map[string]string{
		"INPUT_REPOPATH": "/tmp/repo",
	})
	want := "/tmp/repo/state/${UNKNOWN}"
	if got != want {
		t.Fatalf("expandRuntimeValue() = %q, want %q", got, want)
	}
}
