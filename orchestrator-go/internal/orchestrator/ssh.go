package orchestrator

import (
	"fmt"
	"strings"
)

func buildSshExecutionScript(host string, port int, user string, sshKey string, sshPassword string, script string, envVars []string) string {
	parts := []string{}

	if sshKey != "" {
		parts = append(parts,
			"mkdir -p /root/.ssh",
			fmt.Sprintf("printf %s %s > /root/.ssh/id_rsa", shellEscape("%s"), shellEscape(sshKey)),
			"chmod 600 /root/.ssh/id_rsa",
		)
	}

	if sshPassword != "" {
		parts = append(parts,
			fmt.Sprintf("printf %s %s > /tmp/ssh_pass", shellEscape("%s"), shellEscape(sshPassword)),
			"chmod 600 /tmp/ssh_pass",
			fmt.Sprintf("printf %s %s %s > /tmp/ssh_askpass", shellEscape("%s\\n"), shellEscape("#!/bin/sh"), shellEscape("cat /tmp/ssh_pass")),
			"chmod 700 /tmp/ssh_askpass",
			"export SSH_ASKPASS=/tmp/ssh_askpass",
			"export SSH_ASKPASS_REQUIRE=force",
			"export DISPLAY=doki",
		)
	}

	remoteScript := buildRemoteScript(script, envVars)

	sshOpts := "-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=30"
	if sshPassword != "" {
		sshOpts += " -o BatchMode=no -o PreferredAuthentications=publickey,password,keyboard-interactive -o PasswordAuthentication=yes"
	} else {
		sshOpts += " -o BatchMode=yes"
	}

	userHost := fmt.Sprintf("%s@%s", shellEscape(user), shellEscape(host))
	sshCmd := fmt.Sprintf("ssh %s -p %d %s %s", sshOpts, port, userHost, shellEscape(remoteScript))
	parts = append(parts, sshCmd)

	return strings.Join(parts, " && ")
}

func buildRemoteScript(script string, envVars []string) string {
	exports := []string{}
	for _, env := range envVars {
		key, val, ok := splitEnvVar(env)
		if !ok {
			continue
		}
		exports = append(exports, fmt.Sprintf("export %s=%s", key, shellEscape(val)))
	}

	remote := strings.Join(exports, "; ")
	if remote != "" {
		remote += "; "
	}
	remote += script

	return remote
}

func splitEnvVar(env string) (string, string, bool) {
	parts := strings.SplitN(env, "=", 2)
	if len(parts) != 2 {
		return "", "", false
	}
	return parts[0], parts[1], true
}

func shellEscape(value string) string {
	if value == "" {
		return "''"
	}
	return "'" + strings.ReplaceAll(value, "'", "'\"'\"'") + "'"
}
