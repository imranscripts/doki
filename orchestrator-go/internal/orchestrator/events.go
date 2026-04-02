package orchestrator

import (
	"context"
	"reflect"
	"strings"
	"time"

	"doki/orchestrator/internal/controlplane"
	"doki/orchestrator/internal/model"
)

func EmitJobCreate(ctx context.Context, client *controlplane.Client, jobID string, command *model.Command, template *model.Template, target *model.Target, inputs map[string]interface{}, user map[string]interface{}, status string, dryRun bool) error {
	return EmitJobCreateWithPlan(ctx, client, jobID, command, template, target, inputs, user, status, nil, dryRun)
}

func EmitJobCreateWithPlan(ctx context.Context, client *controlplane.Client, jobID string, command *model.Command, template *model.Template, target *model.Target, inputs map[string]interface{}, user map[string]interface{}, status string, plan interface{}, dryRun bool) error {
	status = strings.TrimSpace(status)
	if status == "" {
		status = "pending"
	}

	payload := map[string]interface{}{
		"jobId":       jobID,
		"commandId":   valueOrNil(command, func(c *model.Command) string { return c.ID }),
		"commandName": valueOrNil(command, func(c *model.Command) string { return c.Name }),
		"templateId":  valueOrNil(template, func(t *model.Template) string { return t.ID }),
		"targetId":    valueOrNil(target, func(t *model.Target) string { return t.ID }),
		"userId":      userValue(user, "id"),
		"username":    userValue(user, "username"),
		"status":      status,
		"isDryRun":    dryRun,
		"inputs":      inputs,
		"startedAt":   time.Now().UTC().Format(time.RFC3339),
	}
	if plan != nil {
		payload["plan"] = plan
	}

	event := map[string]interface{}{"type": "job.create", "payload": payload}
	_, err := client.PostEvents(ctx, []map[string]interface{}{event})
	return err
}

func EmitJobUpdate(ctx context.Context, client *controlplane.Client, jobID string, status string, exitCode int, errMsg string) error {
	payload := map[string]interface{}{
		"jobId":    jobID,
		"status":   status,
		"exitCode": exitCode,
		"error":    errMsg,
	}

	if status == "completed" || status == "failed" || status == "cancelled" || status == "completed_with_warnings" {
		payload["completedAt"] = time.Now().UTC().Format(time.RFC3339)
	}

	event := map[string]interface{}{"type": "job.update", "payload": payload}
	_, err := client.PostEvents(ctx, []map[string]interface{}{event})
	return err
}

func EmitJobOutput(ctx context.Context, client *controlplane.Client, jobID string, output string) error {
	if output == "" {
		return nil
	}
	payload := map[string]interface{}{
		"jobId": jobID,
		"chunk": output,
	}
	_, err := client.PostEvents(ctx, []map[string]interface{}{{"type": "job.append_output", "payload": payload}})
	return err
}

func EmitJobStepCreate(ctx context.Context, client *controlplane.Client, jobID string, stepID string, stepIndex int, template *model.Template, target *model.Target, status string, onFailure string, dependsOn []string, inputs map[string]interface{}, dryRun bool) error {
	status = strings.TrimSpace(status)
	if status == "" {
		status = "pending"
	}

	payload := map[string]interface{}{
		"jobId":      jobID,
		"stepId":     stepID,
		"stepIndex":  stepIndex,
		"templateId": valueOrNil(template, func(t *model.Template) string { return t.ID }),
		"targetId":   valueOrNil(target, func(t *model.Target) string { return t.ID }),
		"status":     status,
		"isDryRun":   dryRun,
		"onFailure":  strings.TrimSpace(onFailure),
		"dependsOn":  dependsOn,
		"inputs":     inputs,
		"startedAt":  time.Now().UTC().Format(time.RFC3339),
	}
	event := map[string]interface{}{"type": "job.step.create", "payload": payload}
	_, err := client.PostEvents(ctx, []map[string]interface{}{event})
	return err
}

func EmitJobStepOutput(ctx context.Context, client *controlplane.Client, jobID string, stepID string, output string) error {
	if output == "" {
		return nil
	}
	payload := map[string]interface{}{
		"jobId":  jobID,
		"stepId": stepID,
		"chunk":  output,
	}
	event := map[string]interface{}{"type": "job.step.append_output", "payload": payload}
	_, err := client.PostEvents(ctx, []map[string]interface{}{event})
	return err
}

func EmitJobStepUpdate(ctx context.Context, client *controlplane.Client, jobID string, stepID string, stepIndex int, status string, exitCode int, errMsg string) error {
	payload := map[string]interface{}{
		"jobId":     jobID,
		"stepId":    stepID,
		"stepIndex": stepIndex,
		"status":    status,
		"exitCode":  exitCode,
		"error":     errMsg,
	}
	if status == "completed" || status == "failed" || status == "cancelled" || status == "skipped" {
		payload["completedAt"] = time.Now().UTC().Format(time.RFC3339)
	}
	event := map[string]interface{}{"type": "job.step.update", "payload": payload}
	_, err := client.PostEvents(ctx, []map[string]interface{}{event})
	return err
}

func valueOrNil[T any](obj T, selector func(T) string) interface{} {
	value := reflect.ValueOf(obj)
	if !value.IsValid() {
		return nil
	}
	switch value.Kind() {
	case reflect.Ptr, reflect.Map, reflect.Slice, reflect.Interface, reflect.Func:
		if value.IsNil() {
			return nil
		}
	}
	if any(obj) == nil {
		return nil
	}
	parsed := selector(obj)
	if parsed == "" {
		return nil
	}
	return parsed
}

func userValue(user map[string]interface{}, key string) interface{} {
	if user == nil {
		return nil
	}
	if value, ok := user[key]; ok {
		return value
	}
	return nil
}
