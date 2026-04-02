package orchestrator

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"os/exec"
	"regexp"
	"strconv"
	"strings"
	"time"

	"doki/orchestrator/internal/model"
)

type ExecSpec struct {
	JobID    string
	Template *model.Template
	Target   *model.Target
	Script   string
	Env      []string
}

type ExecResult struct {
	Output   string
	ExitCode int
	Error    string
}

func ExecuteSync(ctx context.Context, spec ExecSpec) ExecResult {
	if spec.Template == nil {
		return ExecResult{ExitCode: 1, Error: "Template is required"}
	}

	targetType := "local"
	if spec.Target != nil && spec.Target.Type != "" {
		targetType = spec.Target.Type
	}

	timeout := executionTimeout(spec.Template)
	ctx, cancel := context.WithTimeout(ctx, timeout)
	defer cancel()

	switch targetType {
	case "ssh":
		return executeViaSsh(ctx, spec)
	case "docker-exec":
		return executeViaDockerExec(ctx, spec)
	default:
		return executeViaDockerRun(ctx, spec)
	}
}

func executionTimeout(template *model.Template) time.Duration {
	if template == nil {
		return 300 * time.Second
	}
	if template.Execution.Timeout > 0 {
		return time.Duration(template.Execution.Timeout) * time.Second
	}
	return 300 * time.Second
}

func executeViaDockerRun(ctx context.Context, spec ExecSpec) ExecResult {
	image := "alpine:latest"
	if spec.Template != nil && spec.Template.Runtime.Image != "" {
		image = spec.Template.Runtime.Image
	}

	if err := prepareContainer(ctx, image); err != nil {
		return ExecResult{ExitCode: 1, Error: "Failed to prepare container: " + err.Error()}
	}

	args := buildDockerRunArgs(spec, image)
	return runCommand(ctx, args)
}

func executeViaDockerExec(ctx context.Context, spec ExecSpec) ExecResult {
	if spec.Target == nil || spec.Target.Connection == nil {
		return ExecResult{ExitCode: 1, Error: "Docker-exec target requires container name/ID"}
	}
	container, _ := spec.Target.Connection["container"].(string)
	if container == "" {
		return ExecResult{ExitCode: 1, Error: "Docker-exec target requires container name/ID"}
	}

	args := buildDockerExecArgs(spec, container)
	return runCommand(ctx, args)
}

func executeViaSsh(ctx context.Context, spec ExecSpec) ExecResult {
	if spec.Target == nil {
		return ExecResult{ExitCode: 1, Error: "SSH target required"}
	}
	connection := spec.Target.Connection
	if connection == nil {
		return ExecResult{ExitCode: 1, Error: "SSH target requires connection settings"}
	}

	host, _ := connection["host"].(string)
	user, _ := connection["user"].(string)
	if host == "" || user == "" {
		return ExecResult{ExitCode: 1, Error: "SSH target requires host and user"}
	}

	port := 22
	switch v := connection["port"].(type) {
	case float64:
		port = int(v)
	case int:
		port = v
	case string:
		if parsed, err := strconv.Atoi(v); err == nil {
			port = parsed
		}
	}

	sshKey := envValue(spec.Env, "SSH_PRIVATE_KEY")
	sshPassword := envValue(spec.Env, "SSH_PASSWORD")
	if sshPassword == "" {
		sshPassword = envValue(spec.Env, "SSH_PASSPHRASE")
	}

	script := buildSshExecutionScript(host, port, user, sshKey, sshPassword, spec.Script, spec.Env)

	sshImage := "alpine/ssh:latest"
	if err := prepareContainer(ctx, sshImage); err != nil {
		return ExecResult{ExitCode: 1, Error: "Failed to prepare SSH container: " + err.Error()}
	}

	args := []string{
		"docker", "run", "--rm",
		"--label", "doki.job.id=" + spec.JobID,
		"--network", "host",
		sshImage,
		"/bin/sh", "-c", script,
	}

	return runCommand(ctx, args)
}

func buildDockerRunArgs(spec ExecSpec, image string) []string {
	args := []string{"docker", "run", "--rm"}
	args = append(args, "--label", "doki.job.id="+spec.JobID)
	if spec.Template != nil {
		args = append(args, "--label", "doki.template="+spec.Template.ID)
	}

	for _, env := range spec.Env {
		args = append(args, "-e", env)
	}

	envMap := buildEnvMap(spec.Env)

	volumes, needsPrivileged := getTargetVolumes(spec.Target)
	for _, vol := range volumes {
		args = append(args, "-v", vol)
	}
	for _, vol := range getTemplateVolumes(spec.Template, envMap) {
		args = append(args, "-v", vol)
	}
	if needsPrivileged {
		args = append(args, "--privileged")
	}

	network := "host"
	if spec.Template != nil && spec.Template.Runtime.Network != "" {
		network = spec.Template.Runtime.Network
	}
	args = append(args, "--network", network)

	workdir := "/workspace"
	if spec.Template != nil && spec.Template.Runtime.Workdir != "" {
		workdir = spec.Template.Runtime.Workdir
	}
	args = append(args, "-w", workdir)

	args = append(args, image, "/bin/sh", "-c", spec.Script)
	return args
}

func buildDockerExecArgs(spec ExecSpec, container string) []string {
	args := []string{"docker", "exec"}
	args = append(args, "-e", "DOKI_JOB_ID="+spec.JobID)
	for _, env := range spec.Env {
		args = append(args, "-e", env)
	}

	if spec.Target != nil && spec.Target.Connection != nil {
		if workdir, ok := spec.Target.Connection["workdir"].(string); ok && workdir != "" {
			args = append(args, "-w", workdir)
		}
		if execUser, ok := spec.Target.Connection["user"].(string); ok && execUser != "" {
			args = append(args, "-u", execUser)
		}
	}

	args = append(args, container, "/bin/sh", "-c", spec.Script)
	return args
}

func getTargetVolumes(target *model.Target) ([]string, bool) {
	volumes := []string{}
	needsPrivileged := false
	if target == nil {
		return volumes, needsPrivileged
	}
	if target.Type == "docker" || target.Type == "local" {
		socket := "/var/run/docker.sock"
		if target.Connection != nil {
			if value, ok := target.Connection["socket"].(string); ok && value != "" {
				socket = value
			}
		}
		vol := socket + ":/var/run/docker.sock"
		volumes = append(volumes, vol)
		needsPrivileged = strings.Contains(vol, "docker.sock")
	}
	return volumes, needsPrivileged
}

func getTemplateVolumes(template *model.Template, envMap map[string]string) []string {
	volumes := []string{}
	if template == nil {
		return volumes
	}
	for _, vol := range template.Runtime.Volumes {
		host := expandRuntimeValue(strings.TrimSpace(vol.Host), envMap)
		container := expandRuntimeValue(strings.TrimSpace(vol.Container), envMap)
		if host == "" || container == "" {
			continue
		}
		mount := host + ":" + container
		if vol.Readonly {
			mount += ":ro"
		}
		volumes = append(volumes, mount)
	}
	return volumes
}

func buildEnvMap(env []string) map[string]string {
	envMap := map[string]string{}
	for _, entry := range env {
		if entry == "" {
			continue
		}
		parts := strings.SplitN(entry, "=", 2)
		key := strings.TrimSpace(parts[0])
		if key == "" {
			continue
		}
		value := ""
		if len(parts) == 2 {
			value = parts[1]
		}
		envMap[key] = value
	}
	return envMap
}

var runtimeEnvPattern = regexp.MustCompile(`\$\{([A-Za-z0-9_]+)\}`)

func expandRuntimeValue(value string, envMap map[string]string) string {
	if value == "" {
		return ""
	}
	return runtimeEnvPattern.ReplaceAllStringFunc(value, func(match string) string {
		parts := runtimeEnvPattern.FindStringSubmatch(match)
		if len(parts) != 2 {
			return match
		}
		if resolved, ok := envMap[parts[1]]; ok {
			return resolved
		}
		return match
	})
}

func prepareContainer(ctx context.Context, image string) error {
	inspectCtx, cancel := context.WithTimeout(ctx, 5*time.Second)
	defer cancel()

	inspect := exec.CommandContext(inspectCtx, "docker", "image", "inspect", image)
	if err := inspect.Run(); err == nil {
		return nil
	}

	pullCtx, cancelPull := context.WithTimeout(ctx, 5*time.Minute)
	defer cancelPull()

	cmd := exec.CommandContext(pullCtx, "docker", "pull", image)
	output, err := cmd.CombinedOutput()
	if err != nil {
		return fmt.Errorf("failed to pull image %s: %s", image, strings.TrimSpace(string(output)))
	}
	return nil
}

func runCommand(ctx context.Context, args []string) ExecResult {
	if len(args) == 0 {
		return ExecResult{ExitCode: 1, Error: "command not specified"}
	}

	cmd := exec.CommandContext(ctx, args[0], args[1:]...)
	var stdout bytes.Buffer
	var stderr bytes.Buffer
	cmd.Stdout = &stdout
	cmd.Stderr = &stderr

	err := cmd.Run()
	exitCode := 0
	resultErr := ""

	if err != nil {
		if errors.Is(ctx.Err(), context.DeadlineExceeded) {
			exitCode = 124
			resultErr = "Command timed out"
		} else if errors.Is(ctx.Err(), context.Canceled) {
			exitCode = 130
			resultErr = "Cancelled"
		} else if exitError, ok := err.(*exec.ExitError); ok {
			exitCode = exitError.ExitCode()
			resultErr = fmt.Sprintf("Command exited with code %d", exitCode)
		} else {
			exitCode = 1
			resultErr = err.Error()
		}
	}

	output := stdout.String()
	filtered := filterDockerMessages(stderr.String())
	if filtered != "" {
		if output != "" {
			output += "\n"
		}
		output += "--- STDERR ---\n" + filtered
	}

	return ExecResult{Output: output, ExitCode: exitCode, Error: resultErr}
}

var dockerNoisePattern = regexp.MustCompile(`(?i)^(Unable to find image|Pulling from|[a-f0-9]+: (Pulling|Verifying|Download|Pull|Waiting|Extracting)|Digest:|Status:|latest:)`)

func filterDockerMessages(stderr string) string {
	lines := strings.Split(stderr, "\n")
	filtered := []string{}
	for _, line := range lines {
		trimmed := strings.TrimSpace(line)
		if dockerNoisePattern.MatchString(trimmed) {
			continue
		}
		if len(filtered) == 0 && trimmed == "" {
			continue
		}
		filtered = append(filtered, line)
	}

	for len(filtered) > 0 {
		last := strings.TrimSpace(filtered[len(filtered)-1])
		if last != "" {
			break
		}
		filtered = filtered[:len(filtered)-1]
	}

	return strings.Join(filtered, "\n")
}

func envValue(env []string, key string) string {
	prefix := key + "="
	for _, item := range env {
		if strings.HasPrefix(item, prefix) {
			return strings.TrimPrefix(item, prefix)
		}
	}
	return ""
}
