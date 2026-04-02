package orchestrator

import (
	"context"
	"strings"
	"sync"
)

type AsyncJobRegistry struct {
	mu       sync.RWMutex
	cancels  map[string]context.CancelFunc
	canceled map[string]bool
}

func NewAsyncJobRegistry() *AsyncJobRegistry {
	return &AsyncJobRegistry{
		cancels:  map[string]context.CancelFunc{},
		canceled: map[string]bool{},
	}
}

func (r *AsyncJobRegistry) Register(jobID string, cancel context.CancelFunc) {
	jobID = strings.TrimSpace(jobID)
	if jobID == "" || cancel == nil {
		return
	}

	r.mu.Lock()
	r.cancels[jobID] = cancel
	r.mu.Unlock()
}

func (r *AsyncJobRegistry) RequestCancel(jobID string) bool {
	jobID = strings.TrimSpace(jobID)
	if jobID == "" {
		return false
	}

	r.mu.Lock()
	defer r.mu.Unlock()

	cancel := r.cancels[jobID]
	if cancel == nil {
		return false
	}

	r.canceled[jobID] = true
	cancel()
	return true
}

func (r *AsyncJobRegistry) IsCanceled(jobID string) bool {
	jobID = strings.TrimSpace(jobID)
	if jobID == "" {
		return false
	}

	r.mu.RLock()
	defer r.mu.RUnlock()
	return r.canceled[jobID]
}

func (r *AsyncJobRegistry) Done(jobID string) {
	jobID = strings.TrimSpace(jobID)
	if jobID == "" {
		return
	}

	r.mu.Lock()
	delete(r.cancels, jobID)
	delete(r.canceled, jobID)
	r.mu.Unlock()
}
