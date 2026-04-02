package orchestrator

import (
	"context"
	"sort"

	"doki/orchestrator/internal/controlplane"
	"doki/orchestrator/internal/model"
)

type SecretResolution struct {
	Values  map[string]string
	Missing []string
	Locked  bool
}

func CollectSecretIDs(command *model.Command, target *model.Target) []string {
	seen := map[string]struct{}{}

	if command != nil {
		for _, secretID := range command.Secrets {
			if secretID == "" {
				continue
			}
			seen[secretID] = struct{}{}
		}
	}

	if target != nil {
		collectTargetSecrets(target, seen)
	}

	ids := make([]string, 0, len(seen))
	for id := range seen {
		ids = append(ids, id)
	}
	sort.Strings(ids)
	return ids
}

func collectTargetSecrets(target *model.Target, seen map[string]struct{}) {
	connection := target.Connection
	if connection == nil {
		return
	}

	lookup := func(keys ...string) {
		for _, key := range keys {
			if value, ok := connection[key]; ok {
				if str, ok := value.(string); ok && str != "" {
					seen[str] = struct{}{}
				}
			}
		}
	}

	switch target.Type {
	case "ssh":
		lookup("keyRef", "keySecret", "passwordRef", "passwordSecret")
	case "http":
		if authRaw, ok := connection["auth"]; ok {
			if auth, ok := authRaw.(map[string]interface{}); ok {
				if v, ok := auth["usernameSecret"].(string); ok && v != "" {
					seen[v] = struct{}{}
				}
				if v, ok := auth["passwordSecret"].(string); ok && v != "" {
					seen[v] = struct{}{}
				}
				if v, ok := auth["tokenSecret"].(string); ok && v != "" {
					seen[v] = struct{}{}
				}
			}
		}
	}
}

func ResolveSecrets(ctx context.Context, client *controlplane.Client, ids []string) (SecretResolution, error) {
	resolution := SecretResolution{
		Values: map[string]string{},
	}
	if len(ids) == 0 {
		return resolution, nil
	}

	secrets, locked, err := client.FetchSecrets(ctx, ids)
	if locked {
		resolution.Locked = true
		return resolution, err
	}
	if err != nil {
		return resolution, err
	}

	for _, id := range ids {
		value, ok := secrets[id]
		if !ok || value == "" {
			resolution.Missing = append(resolution.Missing, id)
			continue
		}
		resolution.Values[id] = value
	}

	return resolution, nil
}
