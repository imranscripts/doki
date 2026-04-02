package orchestrator

import (
	"fmt"
	"os/exec"
	"strings"
)

type CancelResponse struct {
	Success bool   `json:"success"`
	Error   string `json:"error,omitempty"`
}

func CancelJob(jobID string) CancelResponse {
	jobID = strings.TrimSpace(jobID)
	if jobID == "" {
		return CancelResponse{Success: false, Error: "Job ID required"}
	}

	cmd := exec.Command("docker", "ps", "-q", "--filter", fmt.Sprintf("label=doki.job.id=%s", jobID))
	output, err := cmd.Output()
	if err != nil {
		return CancelResponse{Success: false, Error: "Failed to locate job container"}
	}

	containerIDs := strings.Fields(string(output))
	if len(containerIDs) == 0 {
		return CancelResponse{Success: false, Error: "No running container found for job"}
	}

	killArgs := append([]string{"kill"}, containerIDs...)
	killCmd := exec.Command("docker", killArgs...)
	if err := killCmd.Run(); err != nil {
		return CancelResponse{Success: false, Error: "Failed to cancel job"}
	}

	return CancelResponse{Success: true}
}
