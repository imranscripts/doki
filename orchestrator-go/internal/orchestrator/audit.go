package orchestrator

import (
	"context"
	"encoding/json"
	"fmt"

	"doki/orchestrator/internal/controlplane"
)

func EmitAuditEvent(ctx context.Context, client *controlplane.Client, payload map[string]interface{}) error {
	event := map[string]interface{}{
		"type":    "audit",
		"payload": payload,
	}

	resp, err := client.PostEvents(ctx, []map[string]interface{}{event})
	if err != nil {
		return err
	}
	if !resp.Success {
		if len(resp.Errors) > 0 {
			encoded, _ := json.Marshal(resp.Errors)
			return fmt.Errorf("audit rejected: %s", encoded)
		}
		return fmt.Errorf("audit rejected")
	}
	return nil
}
