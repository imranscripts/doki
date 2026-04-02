package store

import (
	"context"
	"sync"
	"time"

	"doki/orchestrator/internal/controlplane"
	"doki/orchestrator/internal/model"
)

type ConfigStore struct {
	client *controlplane.Client
	ttl    time.Duration

	mu       sync.RWMutex
	bundle   model.Bundle
	indexed  *IndexedBundle
	loadedAt time.Time
}

type IndexedBundle struct {
	Bundle          model.Bundle
	TemplateByID    map[string]*model.Template
	CommandByID     map[string]*model.Command
	TargetByID      map[string]*model.Target
	EnvironmentByID map[string]*model.Environment
}

func NewConfigStore(client *controlplane.Client, ttl time.Duration) *ConfigStore {
	if ttl == 0 {
		ttl = 30 * time.Second
	}
	return &ConfigStore{client: client, ttl: ttl}
}

func (s *ConfigStore) Invalidate() {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.indexed = nil
	s.loadedAt = time.Time{}
}

func (s *ConfigStore) GetBundle(ctx context.Context) (*IndexedBundle, error) {
	s.mu.RLock()
	if s.indexed != nil && time.Since(s.loadedAt) < s.ttl {
		bundle := s.indexed
		s.mu.RUnlock()
		return bundle, nil
	}
	s.mu.RUnlock()

	s.mu.Lock()
	defer s.mu.Unlock()
	if s.indexed != nil && time.Since(s.loadedAt) < s.ttl {
		return s.indexed, nil
	}

	bundle, err := s.client.FetchConfigBundle(ctx)
	if err != nil {
		return nil, err
	}

	indexed := indexBundle(bundle)
	indexed.Bundle = bundle
	indexed.Bundle.GeneratedAt = bundle.GeneratedAt

	s.bundle = bundle
	s.indexed = indexed
	s.loadedAt = time.Now()

	return indexed, nil
}

func indexBundle(bundle model.Bundle) *IndexedBundle {
	idx := &IndexedBundle{
		Bundle:          bundle,
		TemplateByID:    map[string]*model.Template{},
		CommandByID:     map[string]*model.Command{},
		TargetByID:      map[string]*model.Target{},
		EnvironmentByID: map[string]*model.Environment{},
	}

	for i := range bundle.Templates {
		t := &bundle.Templates[i]
		idx.TemplateByID[t.ID] = t
	}
	for i := range bundle.Commands {
		c := &bundle.Commands[i]
		idx.CommandByID[c.ID] = c
	}
	for i := range bundle.Targets {
		t := &bundle.Targets[i]
		idx.TargetByID[t.ID] = t
	}
	for id, env := range bundle.Environments {
		copyEnv := env
		idx.EnvironmentByID[id] = &copyEnv
	}

	return idx
}
