package controlplane

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
	"time"

	"doki/orchestrator/internal/model"
)

type Client struct {
	BaseURL string
	Token   string
	HTTP    *http.Client
}

func NewClient(baseURL, token string) *Client {
	return &Client{
		BaseURL: strings.TrimRight(baseURL, "/"),
		Token:   token,
		HTTP: &http.Client{
			Timeout: 15 * time.Second,
		},
	}
}

func (c *Client) FetchConfigBundle(ctx context.Context) (model.Bundle, error) {
	var empty model.Bundle
	if c.Token == "" {
		return empty, errors.New("control plane token not configured")
	}

	endpoint, err := c.buildURL("/api/internal/orchestrator.php", map[string]string{"action": "config"})
	if err != nil {
		return empty, err
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodGet, endpoint, nil)
	if err != nil {
		return empty, err
	}
	c.applyAuth(req)

	resp, err := c.HTTP.Do(req)
	if err != nil {
		return empty, err
	}
	defer resp.Body.Close()

	body, err := io.ReadAll(resp.Body)
	if err != nil {
		return empty, err
	}

	var parsed model.ConfigResponse
	if err := json.Unmarshal(body, &parsed); err != nil {
		return empty, fmt.Errorf("invalid config response: %w", err)
	}

	if !parsed.Success {
		if parsed.Error == "" {
			parsed.Error = "control plane config fetch failed"
		}
		return empty, errors.New(parsed.Error)
	}

	return parsed.Bundle, nil
}

func (c *Client) FetchPlaywrightProject(ctx context.Context, projectID string) (map[string]interface{}, error) {
	if c.Token == "" {
		return nil, errors.New("control plane token not configured")
	}

	query := map[string]string{"action": "playwright-project"}
	if strings.TrimSpace(projectID) != "" {
		query["id"] = strings.TrimSpace(projectID)
	}
	endpoint, err := c.buildURL("/api/internal/orchestrator.php", query)
	if err != nil {
		return nil, err
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodGet, endpoint, nil)
	if err != nil {
		return nil, err
	}
	c.applyAuth(req)

	resp, err := c.HTTP.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()

	body, err := io.ReadAll(resp.Body)
	if err != nil {
		return nil, err
	}

	var raw map[string]interface{}
	if err := json.Unmarshal(body, &raw); err != nil {
		return nil, fmt.Errorf("invalid playwright project response: %w", err)
	}

	success, _ := raw["success"].(bool)
	if !success {
		if msg, ok := raw["error"].(string); ok && msg != "" {
			return nil, errors.New(msg)
		}
		return nil, errors.New("playwright project lookup failed")
	}

	project, _ := raw["project"].(map[string]interface{})
	if project == nil {
		return nil, errors.New("playwright project missing in response")
	}
	return project, nil
}

func (c *Client) FetchJob(ctx context.Context, jobID string) (map[string]interface{}, error) {
	endpoint, err := c.buildURL("/api/internal/orchestrator.php", map[string]string{
		"action": "job",
		"id":     jobID,
	})
	if err != nil {
		return nil, err
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodGet, endpoint, nil)
	if err != nil {
		return nil, err
	}
	c.applyAuth(req)

	resp, err := c.HTTP.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()

	body, err := io.ReadAll(resp.Body)
	if err != nil {
		return nil, err
	}

	var raw map[string]interface{}
	if err := json.Unmarshal(body, &raw); err != nil {
		return nil, err
	}

	success, _ := raw["success"].(bool)
	if !success {
		if msg, ok := raw["error"].(string); ok && msg != "" {
			return nil, errors.New(msg)
		}
		return nil, errors.New("job lookup failed")
	}

	job, _ := raw["job"].(map[string]interface{})
	return job, nil
}

func (c *Client) FetchJobs(ctx context.Context, userID string, limit int) ([]map[string]interface{}, error) {
	query := map[string]string{
		"action": "jobs",
	}
	if userID != "" {
		query["userId"] = userID
	}
	if limit > 0 {
		query["limit"] = fmt.Sprintf("%d", limit)
	}
	endpoint, err := c.buildURL("/api/internal/orchestrator.php", query)
	if err != nil {
		return nil, err
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodGet, endpoint, nil)
	if err != nil {
		return nil, err
	}
	c.applyAuth(req)

	resp, err := c.HTTP.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()

	body, err := io.ReadAll(resp.Body)
	if err != nil {
		return nil, err
	}

	var raw map[string]interface{}
	if err := json.Unmarshal(body, &raw); err != nil {
		return nil, err
	}
	success, _ := raw["success"].(bool)
	if !success {
		if msg, ok := raw["error"].(string); ok && msg != "" {
			return nil, errors.New(msg)
		}
		return nil, errors.New("jobs lookup failed")
	}

	jobsRaw, _ := raw["jobs"].([]interface{})
	jobs := make([]map[string]interface{}, 0, len(jobsRaw))
	for _, item := range jobsRaw {
		if job, ok := item.(map[string]interface{}); ok {
			jobs = append(jobs, job)
		}
	}
	return jobs, nil
}

func (c *Client) FetchSecrets(ctx context.Context, ids []string) (map[string]string, bool, error) {
	if c.Token == "" {
		return nil, false, errors.New("control plane token not configured")
	}

	payload := map[string]interface{}{
		"secretIds": ids,
	}
	body, err := json.Marshal(payload)
	if err != nil {
		return nil, false, err
	}

	endpoint, err := c.buildURL("/api/internal/orchestrator.php", map[string]string{"action": "secrets"})
	if err != nil {
		return nil, false, err
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodPost, endpoint, bytes.NewReader(body))
	if err != nil {
		return nil, false, err
	}
	c.applyAuth(req)
	req.Header.Set("Content-Type", "application/json")

	resp, err := c.HTTP.Do(req)
	if err != nil {
		return nil, false, err
	}
	defer resp.Body.Close()

	respBody, err := io.ReadAll(resp.Body)
	if err != nil {
		return nil, false, err
	}

	var parsed model.SecretsResponse
	if err := json.Unmarshal(respBody, &parsed); err != nil {
		return nil, false, fmt.Errorf("invalid secrets response: %w", err)
	}

	if parsed.Locked {
		return nil, true, errors.New(parsed.Error)
	}
	if !parsed.Success {
		return nil, false, errors.New(parsed.Error)
	}

	return parsed.Secrets, false, nil
}

func (c *Client) PostEvents(ctx context.Context, events []map[string]interface{}) (model.EventsResponse, error) {
	var empty model.EventsResponse
	if c.Token == "" {
		return empty, errors.New("control plane token not configured")
	}

	payload := map[string]interface{}{"events": events}
	body, err := json.Marshal(payload)
	if err != nil {
		return empty, err
	}

	endpoint, err := c.buildURL("/api/internal/orchestrator.php", map[string]string{"action": "events"})
	if err != nil {
		return empty, err
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodPost, endpoint, bytes.NewReader(body))
	if err != nil {
		return empty, err
	}
	c.applyAuth(req)
	req.Header.Set("Content-Type", "application/json")

	resp, err := c.HTTP.Do(req)
	if err != nil {
		return empty, err
	}
	defer resp.Body.Close()

	respBody, err := io.ReadAll(resp.Body)
	if err != nil {
		return empty, err
	}

	if err := json.Unmarshal(respBody, &empty); err != nil {
		return empty, err
	}

	if !empty.Success && len(empty.Errors) == 0 {
		return empty, errors.New("control plane rejected events")
	}

	return empty, nil
}

func (c *Client) applyAuth(req *http.Request) {
	if c.Token == "" {
		return
	}
	req.Header.Set("X-Doki-Orch-Token", c.Token)
}

func (c *Client) buildURL(path string, query map[string]string) (string, error) {
	base, err := url.Parse(c.BaseURL)
	if err != nil {
		return "", err
	}
	base.Path = strings.TrimRight(base.Path, "/") + path
	if len(query) > 0 {
		q := base.Query()
		for key, value := range query {
			q.Set(key, value)
		}
		base.RawQuery = q.Encode()
	}
	return base.String(), nil
}
