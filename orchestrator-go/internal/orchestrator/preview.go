package orchestrator

import (
	"regexp"
	"strconv"
	"strings"

	"doki/orchestrator/internal/model"
)

type CommandPreviewSpec struct {
	JobID        string
	Template     *model.Template
	Target       *model.Target
	Script       string
	Env          []string
	SecretEnvMap map[string]string
	SecretValues map[string]string
}

var shellSafeArgPattern = regexp.MustCompile(`^[A-Za-z0-9_@%+=:,./-]+$`)

func BuildSecretPlaceholders(ids []string) map[string]string {
	placeholders := make(map[string]string, len(ids))
	for _, id := range ids {
		id = strings.TrimSpace(id)
		if id == "" {
			continue
		}
		placeholders[id] = secretPlaceholder(id)
	}
	return placeholders
}

func BuildCommandPreview(spec CommandPreviewSpec) string {
	args, executor := buildPreviewArgs(spec)
	redactedArgs := redactCommandArgs(args, spec.SecretEnvMap, spec.SecretValues)

	lines := []string{
		"Dry run: no commands were executed.",
		"Executor: " + executor,
		"Redacted command:",
		shellJoin(redactedArgs),
	}

	return strings.Join(lines, "\n")
}

func BuildDryRunExecResult(spec CommandPreviewSpec) ExecResult {
	return ExecResult{
		Output:   BuildCommandPreview(spec),
		ExitCode: 0,
	}
}

func buildPreviewArgs(spec CommandPreviewSpec) ([]string, string) {
	if spec.Target != nil {
		switch spec.Target.Type {
		case "ssh":
			host, _ := spec.Target.Connection["host"].(string)
			user, _ := spec.Target.Connection["user"].(string)
			port := 22
			if spec.Target.Connection != nil {
				switch value := spec.Target.Connection["port"].(type) {
				case float64:
					port = int(value)
				case int:
					port = value
				case string:
					if parsed, err := strconv.Atoi(strings.TrimSpace(value)); err == nil {
						port = parsed
					}
				}
			}

			script := buildSshExecutionScript(
				host,
				port,
				user,
				envValue(spec.Env, "SSH_PRIVATE_KEY"),
				firstEnvValue(spec.Env, "SSH_PASSWORD", "SSH_PASSPHRASE"),
				spec.Script,
				spec.Env,
			)
			return []string{
				"docker", "run", "--rm",
				"--label", "doki.job.id=" + spec.JobID,
				"--network", "host",
				"alpine/ssh:latest",
				"/bin/sh", "-c", script,
			}, "ssh"
		case "docker-exec":
			container, _ := spec.Target.Connection["container"].(string)
			return buildDockerExecArgs(ExecSpec{
				JobID:    spec.JobID,
				Template: spec.Template,
				Target:   spec.Target,
				Script:   spec.Script,
				Env:      spec.Env,
			}, container), "docker exec"
		}
	}

	image := "alpine:latest"
	if spec.Template != nil && strings.TrimSpace(spec.Template.Runtime.Image) != "" {
		image = strings.TrimSpace(spec.Template.Runtime.Image)
	}

	return buildDockerRunArgs(ExecSpec{
		JobID:    spec.JobID,
		Template: spec.Template,
		Target:   spec.Target,
		Script:   spec.Script,
		Env:      spec.Env,
	}, image), "docker run"
}

func redactCommandArgs(args []string, secretEnvMap map[string]string, secretValues map[string]string) []string {
	if len(args) == 0 {
		return []string{}
	}

	redacted := make([]string, 0, len(args))
	for _, arg := range args {
		redacted = append(redacted, redactCommandArg(arg, secretEnvMap, secretValues))
	}
	return redacted
}

func redactCommandArg(arg string, secretEnvMap map[string]string, secretValues map[string]string) string {
	if eq := strings.Index(arg, "="); eq > 0 {
		key := arg[:eq]
		if secretID, ok := secretEnvMap[key]; ok && strings.TrimSpace(secretID) != "" {
			return key + "=" + secretPlaceholder(secretID)
		}
	}

	result := arg
	for secretID, secretValue := range secretValues {
		if strings.TrimSpace(secretID) == "" || secretValue == "" {
			continue
		}
		result = strings.ReplaceAll(result, secretValue, secretPlaceholder(secretID))
	}
	return result
}

func secretPlaceholder(secretID string) string {
	secretID = strings.TrimSpace(secretID)
	if secretID == "" {
		return "[secret]"
	}
	return "[secret:" + secretID + "]"
}

func firstEnvValue(env []string, keys ...string) string {
	for _, key := range keys {
		if value := envValue(env, key); value != "" {
			return value
		}
	}
	return ""
}

func shellJoin(args []string) string {
	quoted := make([]string, 0, len(args))
	for _, arg := range args {
		quoted = append(quoted, shellQuote(arg))
	}
	return strings.Join(quoted, " ")
}

func shellQuote(value string) string {
	if value == "" {
		return "''"
	}
	if shellSafeArgPattern.MatchString(value) {
		return value
	}
	return "'" + strings.ReplaceAll(value, "'", `'"'"'`) + "'"
}
