package orchestrator

import (
	"encoding/json"
	"fmt"
	"strings"

	"doki/orchestrator/internal/model"
)

type EnvBuildResult struct {
	Env          []string
	SecretsUsed  []string
	SecretEnvMap map[string]string
}

func BuildEnvVars(template *model.Template, target *model.Target, inputs map[string]interface{}, bindings map[string]string, command *model.Command, secrets map[string]string) EnvBuildResult {
	result := EnvBuildResult{
		Env:          []string{},
		SecretsUsed:  []string{},
		SecretEnvMap: map[string]string{},
	}

	for key, value := range inputs {
		if isScalar(value) {
			result.Env = append(result.Env, fmt.Sprintf("INPUT_%s=%s", strings.ToUpper(key), fmt.Sprint(value)))
		}
	}

	if command != nil {
		for envName, secretID := range command.Secrets {
			if secretID == "" {
				continue
			}
			secretValue, ok := secrets[secretID]
			if ok && secretValue != "" {
				result.Env = append(result.Env, fmt.Sprintf("%s=%s", envName, secretValue))
				result.SecretsUsed = append(result.SecretsUsed, secretID)
				result.SecretEnvMap[envName] = secretID
			}
		}
	}

	for envName, value := range bindings {
		if value == "" {
			continue
		}
		result.Env = append(result.Env, fmt.Sprintf("%s=%s", envName, value))
	}

	if target != nil {
		switch target.Type {
		case "http":
			applyHttpEnv(&result, target, secrets)
		case "ssh":
			applySshEnv(&result, target, secrets)
		}
	}

	return result
}

func isScalar(value interface{}) bool {
	switch value.(type) {
	case string, bool, int, int64, float64, float32, json.Number:
		return true
	default:
		return false
	}
}

func applyHttpEnv(result *EnvBuildResult, target *model.Target, secrets map[string]string) {
	if target.Connection == nil {
		return
	}
	if baseUrl, ok := target.Connection["baseUrl"].(string); ok && baseUrl != "" {
		result.Env = append(result.Env, fmt.Sprintf("HTTP_ENDPOINT=%s", baseUrl))
	}

	authRaw, ok := target.Connection["auth"]
	if !ok {
		return
	}
	auth, ok := authRaw.(map[string]interface{})
	if !ok {
		return
	}
	authType, _ := auth["type"].(string)
	if authType == "" {
		return
	}

	result.Env = append(result.Env, fmt.Sprintf("HTTP_AUTH_TYPE=%s", authType))

	switch authType {
	case "basic":
		if secretID, ok := auth["usernameSecret"].(string); ok && secretID != "" {
			if value, ok := secrets[secretID]; ok && value != "" {
				result.Env = append(result.Env, fmt.Sprintf("HTTP_AUTH_USERNAME=%s", value))
				result.SecretsUsed = append(result.SecretsUsed, secretID)
				result.SecretEnvMap["HTTP_AUTH_USERNAME"] = secretID
			}
		}
		if secretID, ok := auth["passwordSecret"].(string); ok && secretID != "" {
			if value, ok := secrets[secretID]; ok && value != "" {
				result.Env = append(result.Env, fmt.Sprintf("HTTP_AUTH_PASSWORD=%s", value))
				result.SecretsUsed = append(result.SecretsUsed, secretID)
				result.SecretEnvMap["HTTP_AUTH_PASSWORD"] = secretID
			}
		}
	case "bearer":
		if secretID, ok := auth["tokenSecret"].(string); ok && secretID != "" {
			if value, ok := secrets[secretID]; ok && value != "" {
				result.Env = append(result.Env, fmt.Sprintf("HTTP_AUTH_TOKEN=%s", value))
				result.SecretsUsed = append(result.SecretsUsed, secretID)
				result.SecretEnvMap["HTTP_AUTH_TOKEN"] = secretID
			}
		}
	}
}

func applySshEnv(result *EnvBuildResult, target *model.Target, secrets map[string]string) {
	if target.Connection == nil {
		return
	}
	if host, ok := target.Connection["host"].(string); ok && host != "" {
		result.Env = append(result.Env, fmt.Sprintf("SSH_HOST=%s", host))
	}
	if port, ok := target.Connection["port"].(string); ok && port != "" {
		result.Env = append(result.Env, fmt.Sprintf("SSH_PORT=%s", port))
	}
	if portNum, ok := target.Connection["port"].(float64); ok {
		result.Env = append(result.Env, fmt.Sprintf("SSH_PORT=%d", int(portNum)))
	}
	if user, ok := target.Connection["user"].(string); ok && user != "" {
		result.Env = append(result.Env, fmt.Sprintf("SSH_USER=%s", user))
	}

	keyRef := ""
	if value, ok := target.Connection["keyRef"].(string); ok {
		keyRef = value
	}
	if keyRef == "" {
		if value, ok := target.Connection["keySecret"].(string); ok {
			keyRef = value
		}
	}
	if keyRef != "" {
		if value, ok := secrets[keyRef]; ok && value != "" {
			result.Env = append(result.Env, fmt.Sprintf("SSH_PRIVATE_KEY=%s", value))
			result.SecretsUsed = append(result.SecretsUsed, keyRef)
			result.SecretEnvMap["SSH_PRIVATE_KEY"] = keyRef
		}
	}

	passwordRef := ""
	if value, ok := target.Connection["passwordRef"].(string); ok {
		passwordRef = value
	}
	if passwordRef == "" {
		if value, ok := target.Connection["passwordSecret"].(string); ok {
			passwordRef = value
		}
	}
	if passwordRef != "" {
		if value, ok := secrets[passwordRef]; ok && value != "" {
			result.Env = append(result.Env, fmt.Sprintf("SSH_PASSWORD=%s", value))
			result.SecretsUsed = append(result.SecretsUsed, passwordRef)
			result.SecretEnvMap["SSH_PASSWORD"] = passwordRef
		}
	}
}
