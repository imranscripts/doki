package controlplane

import (
	"context"
	"io"
	"net/http"
	"strings"
	"testing"
)

type roundTripFunc func(*http.Request) (*http.Response, error)

func (fn roundTripFunc) RoundTrip(req *http.Request) (*http.Response, error) {
	return fn(req)
}

func jsonResponse(req *http.Request, body string) *http.Response {
	return &http.Response{
		StatusCode: http.StatusOK,
		Body:       io.NopCloser(strings.NewReader(body)),
		Header:     make(http.Header),
		Request:    req,
	}
}

func TestFetchJobPreservesStepsAndSummary(t *testing.T) {
	client := NewClient("http://controlplane.test", "test-token")
	client.HTTP = &http.Client{
		Transport: roundTripFunc(func(req *http.Request) (*http.Response, error) {
			if req.Method != http.MethodGet {
				t.Fatalf("method = %s, want GET", req.Method)
			}
			if got := req.URL.Query().Get("action"); got != "job" {
				t.Fatalf("action = %s, want job", got)
			}
			if got := req.URL.Query().Get("id"); got != "job-1" {
				t.Fatalf("id = %s, want job-1", got)
			}
			if got := req.Header.Get("X-Doki-Orch-Token"); got != "test-token" {
				t.Fatalf("auth header = %q, want test-token", got)
			}

			body := `{
				"success": true,
				"job": {
					"id": "job-1",
					"status": "running",
					"steps": [
						{"stepId": "copy", "status": "completed"},
						{"stepId": "exec", "status": "pending"}
					],
					"stepsSummary": {
						"total": 2,
						"completed": 1,
						"pending": 1,
						"failed": 0
					}
				}
			}`
			return jsonResponse(req, body), nil
		}),
	}

	job, err := client.FetchJob(context.Background(), "job-1")
	if err != nil {
		t.Fatalf("FetchJob() error = %v", err)
	}

	if got, _ := job["id"].(string); got != "job-1" {
		t.Fatalf("job id = %v, want job-1", got)
	}

	steps, ok := job["steps"].([]interface{})
	if !ok {
		t.Fatalf("job steps type = %T, want []interface{}", job["steps"])
	}
	if len(steps) != 2 {
		t.Fatalf("steps len = %d, want 2", len(steps))
	}

	step0, ok := steps[0].(map[string]interface{})
	if !ok {
		t.Fatalf("steps[0] type = %T, want map[string]interface{}", steps[0])
	}
	if got, _ := step0["stepId"].(string); got != "copy" {
		t.Fatalf("steps[0].stepId = %v, want copy", got)
	}

	summary, ok := job["stepsSummary"].(map[string]interface{})
	if !ok {
		t.Fatalf("stepsSummary type = %T, want map[string]interface{}", job["stepsSummary"])
	}
	if got, _ := summary["total"].(float64); got != 2 {
		t.Fatalf("stepsSummary.total = %v, want 2", got)
	}
}

func TestFetchJobsPreservesStepsAndSummary(t *testing.T) {
	client := NewClient("http://controlplane.test", "test-token")
	client.HTTP = &http.Client{
		Transport: roundTripFunc(func(req *http.Request) (*http.Response, error) {
			if req.Method != http.MethodGet {
				t.Fatalf("method = %s, want GET", req.Method)
			}
			if got := req.URL.Query().Get("action"); got != "jobs" {
				t.Fatalf("action = %s, want jobs", got)
			}
			if got := req.URL.Query().Get("userId"); got != "u-1" {
				t.Fatalf("userId = %s, want u-1", got)
			}
			if got := req.URL.Query().Get("limit"); got != "25" {
				t.Fatalf("limit = %s, want 25", got)
			}
			if got := req.Header.Get("X-Doki-Orch-Token"); got != "test-token" {
				t.Fatalf("auth header = %q, want test-token", got)
			}

			body := `{
				"success": true,
				"jobs": [
					{
						"id": "job-1",
						"steps": [
							{"stepId": "copy", "status": "completed"}
						],
						"stepsSummary": {"total": 1, "completed": 1}
					},
					{
						"id": "job-2",
						"stepsSummary": {"total": 2, "pending": 2}
					}
				]
			}`
			return jsonResponse(req, body), nil
		}),
	}

	jobs, err := client.FetchJobs(context.Background(), "u-1", 25)
	if err != nil {
		t.Fatalf("FetchJobs() error = %v", err)
	}
	if len(jobs) != 2 {
		t.Fatalf("jobs len = %d, want 2", len(jobs))
	}

	firstSteps, ok := jobs[0]["steps"].([]interface{})
	if !ok {
		t.Fatalf("jobs[0].steps type = %T, want []interface{}", jobs[0]["steps"])
	}
	if len(firstSteps) != 1 {
		t.Fatalf("jobs[0].steps len = %d, want 1", len(firstSteps))
	}

	secondSummary, ok := jobs[1]["stepsSummary"].(map[string]interface{})
	if !ok {
		t.Fatalf("jobs[1].stepsSummary type = %T, want map[string]interface{}", jobs[1]["stepsSummary"])
	}
	if got, _ := secondSummary["pending"].(float64); got != 2 {
		t.Fatalf("jobs[1].stepsSummary.pending = %v, want 2", got)
	}
}
