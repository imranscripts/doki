package playwright

import (
	"bufio"
	"context"
	"crypto/rand"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"log"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"sync"
	"time"

	"doki/orchestrator/internal/controlplane"
	"doki/orchestrator/internal/model"
	"doki/orchestrator/internal/orchestrator"
	"doki/orchestrator/internal/store"
)

const (
	defaultRunnerImage = "doki/playwright-runner:v4"
	maxScannerLineSize = 4 * 1024 * 1024
)

var (
	projectIDPattern          = regexp.MustCompile(`^[A-Za-z0-9_-]+$`)
	playwrightTestFilePattern = regexp.MustCompile(`\.(?:spec|test)\.(?:[cm]?[jt]sx?)$`)
	playwrightConfigFiles     = []string{
		"playwright.config.ts",
		"playwright.config.js",
		"playwright.config.mts",
		"playwright.config.mjs",
		"playwright.config.cts",
		"playwright.config.cjs",
	}
)

type Manager struct {
	appDir      string
	hostAppDir  string
	runnerImage string
	client      *controlplane.Client
	configStore *store.ConfigStore

	mu       sync.RWMutex
	running  map[string]jobRuntime
	canceled map[string]bool
}

type RunRequest struct {
	ProjectID string                 `json:"projectId"`
	TestFiles []string               `json:"testFiles"`
	TestName  string                 `json:"testName"`
	BaseURL   string                 `json:"baseUrl"`
	TargetID  string                 `json:"targetId"`
	User      map[string]interface{} `json:"user"`
}

type RunResponse struct {
	Success   bool     `json:"success"`
	JobID     string   `json:"jobId,omitempty"`
	Project   string   `json:"project,omitempty"`
	TestFiles []string `json:"testFiles,omitempty"`
	TestName  string   `json:"testName,omitempty"`
	Error     string   `json:"error,omitempty"`
}

type JobStatusResponse struct {
	Success    bool   `json:"success"`
	Status     string `json:"status,omitempty"`
	Output     string `json:"output,omitempty"`
	VideoPath  string `json:"videoPath"`
	IsComplete bool   `json:"isComplete"`
	Error      string `json:"error,omitempty"`
}

type CleanupRequest struct {
	RetentionDays int `json:"retentionDays"`
	MaxReports    int `json:"maxReports"`
}

type CleanupResponse struct {
	Success bool   `json:"success"`
	Deleted int    `json:"deleted"`
	Error   string `json:"error,omitempty"`
}

type jobRuntime struct {
	ArchivePath      string
	OutputFile       string
	StatusFile       string
	LegacyStatusFile string
	LegacyResultFile string
	ProjectDir       string
	StartTimestamp   string
}

type wrapperReporters struct {
	HTML  bool
	List  bool
	JSON  bool
	JUnit bool
}

type wrapperRecording struct {
	Video      string
	Screenshot string
	Trace      string
}

type wrapperExecution struct {
	Retries int
	Workers int
	Timeout int
}

type wrapperSettings struct {
	OverrideReporters bool
	Reporters         wrapperReporters
	OverrideRecording bool
	Recording         wrapperRecording
	OverrideExecution bool
	Execution         wrapperExecution
}

func defaultWrapperSettings() wrapperSettings {
	return wrapperSettings{
		OverrideReporters: true,
		Reporters: wrapperReporters{
			HTML:  true,
			List:  true,
			JSON:  false,
			JUnit: false,
		},
		OverrideRecording: true,
		Recording: wrapperRecording{
			Video:      "on-first-retry",
			Screenshot: "only-on-failure",
			Trace:      "on-first-retry",
		},
		OverrideExecution: false,
		Execution: wrapperExecution{
			Retries: 2,
			Workers: 1,
			Timeout: 180000,
		},
	}
}

func NewManager(appDir, hostAppDir string, client *controlplane.Client, configStore *store.ConfigStore) *Manager {
	if strings.TrimSpace(appDir) == "" {
		appDir = "/var/www/html"
	}
	return &Manager{
		appDir:      strings.TrimRight(appDir, "/"),
		hostAppDir:  strings.TrimRight(strings.TrimSpace(hostAppDir), "/"),
		runnerImage: defaultRunnerImage,
		client:      client,
		configStore: configStore,
		running:     map[string]jobRuntime{},
		canceled:    map[string]bool{},
	}
}

func (m *Manager) StartRun(ctx context.Context, req RunRequest) (RunResponse, int) {
	req.TestName = strings.TrimSpace(req.TestName)
	if req.TestName == "" {
		req.TestName = "Test Run"
	}

	projectDir, projectLabel, err := m.resolveProject(req.ProjectID)
	if err != nil {
		return RunResponse{Success: false, Error: err.Error()}, http.StatusBadRequest
	}

	target, err := m.resolveTarget(ctx, strings.TrimSpace(req.TargetID))
	if err != nil {
		return RunResponse{Success: false, Error: err.Error()}, http.StatusBadRequest
	}

	jobID := generatePlaywrightJobID()
	rt, err := m.createArchive(jobID, projectLabel, req.TestFiles, req.TestName, projectDir)
	if err != nil {
		return RunResponse{Success: false, Error: "Failed to initialize run archive: " + err.Error()}, http.StatusInternalServerError
	}

	if m.client == nil {
		return RunResponse{Success: false, Error: "Control plane client unavailable"}, http.StatusBadGateway
	}

	command := &model.Command{
		ID:         "playwright.run",
		Name:       req.TestName,
		TemplateID: "playwright",
		TargetID:   req.TargetID,
	}
	inputs := map[string]interface{}{
		"projectId": req.ProjectID,
		"testFiles": req.TestFiles,
		"baseUrl":   req.BaseURL,
	}

	eventCtx, cancel := context.WithTimeout(ctx, 10*time.Second)
	defer cancel()
	if err := orchestrator.EmitJobCreate(eventCtx, m.client, jobID, command, nil, target, inputs, req.User, "running", false); err != nil {
		return RunResponse{Success: false, Error: "Failed to persist Playwright job: " + err.Error()}, http.StatusBadGateway
	}

	m.setRuntime(jobID, rt)
	go m.runAsync(jobID, req, target, rt)

	return RunResponse{
		Success:   true,
		JobID:     jobID,
		Project:   req.ProjectID,
		TestFiles: effectiveTestFiles(req.TestFiles),
		TestName:  req.TestName,
	}, http.StatusOK
}

func (m *Manager) GetJobStatus(ctx context.Context, jobID string) (JobStatusResponse, int) {
	jobID = strings.TrimSpace(jobID)
	if jobID == "" {
		return JobStatusResponse{Success: false, Error: "Job ID required"}, http.StatusBadRequest
	}
	if m.client == nil {
		return JobStatusResponse{Success: false, Error: "Control plane client unavailable"}, http.StatusBadGateway
	}

	job, err := m.client.FetchJob(ctx, jobID)
	if err != nil {
		return JobStatusResponse{Success: false, Error: err.Error()}, http.StatusNotFound
	}

	status := readString(job, "status")
	output := readString(job, "output")
	videoPath := m.findLegacyVideoPath(jobID)
	return JobStatusResponse{
		Success:    true,
		Status:     status,
		Output:     output,
		VideoPath:  videoPath,
		IsComplete: status == "completed" || status == "failed" || status == "cancelled",
	}, http.StatusOK
}

func (m *Manager) CancelJob(ctx context.Context, jobID string) (orchestrator.CancelResponse, int) {
	jobID = strings.TrimSpace(jobID)
	if jobID == "" {
		return orchestrator.CancelResponse{Success: false, Error: "Job ID required"}, http.StatusBadRequest
	}

	result := orchestrator.CancelJob(jobID)
	if !result.Success {
		return result, http.StatusBadRequest
	}

	m.setCanceled(jobID, true)
	if rt, ok := m.getRuntime(jobID); ok {
		_ = writeTextFile(rt.StatusFile, "cancelled")
		_ = writeTextFile(rt.LegacyStatusFile, "cancelled")
	}

	if m.client != nil {
		updateCtx, cancel := context.WithTimeout(ctx, 10*time.Second)
		defer cancel()
		if err := orchestrator.EmitJobUpdate(updateCtx, m.client, jobID, "cancelled", -1, "Cancelled"); err != nil {
			log.Printf("playwright cancel update failed: %v", err)
		}
	}
	return result, http.StatusOK
}

func (m *Manager) CleanupReports(req CleanupRequest) (CleanupResponse, int) {
	retentionDays := req.RetentionDays
	if retentionDays <= 0 {
		retentionDays = 30
	}
	maxReports := req.MaxReports
	if maxReports <= 0 {
		maxReports = 50
	}

	archiveDir := filepath.Join(m.appDir, "playwright-reports-archive")
	entries, err := os.ReadDir(archiveDir)
	if err != nil {
		if errors.Is(err, os.ErrNotExist) {
			return CleanupResponse{Success: true, Deleted: 0}, http.StatusOK
		}
		return CleanupResponse{Success: false, Error: err.Error()}, http.StatusInternalServerError
	}

	type item struct {
		name string
		path string
		mod  time.Time
	}
	items := make([]item, 0, len(entries))
	for _, entry := range entries {
		if !entry.IsDir() {
			continue
		}
		path := filepath.Join(archiveDir, entry.Name())
		info, statErr := os.Stat(path)
		if statErr != nil {
			continue
		}
		items = append(items, item{name: entry.Name(), path: path, mod: info.ModTime()})
	}

	sort.Slice(items, func(i, j int) bool {
		return items[i].mod.Before(items[j].mod)
	})

	cutoff := time.Now().Add(-time.Duration(retentionDays) * 24 * time.Hour)
	deleted := 0
	total := len(items)

	for _, entry := range items {
		shouldDelete := entry.mod.Before(cutoff) || (total-deleted) > maxReports
		if !shouldDelete {
			continue
		}
		if err := os.RemoveAll(entry.path); err == nil {
			deleted++
		}
	}

	return CleanupResponse{Success: true, Deleted: deleted}, http.StatusOK
}

func (m *Manager) runAsync(jobID string, req RunRequest, target *model.Target, rt jobRuntime) {
	defer m.clearRuntime(jobID)

	jobCtx, cancel := context.WithTimeout(context.Background(), 60*time.Minute)
	defer cancel()

	logger := func(line string) {
		_ = appendLine(rt.OutputFile, line)
	}

	logHeader(logger, jobID, req, rt.ArchivePath)

	dockerBaseArgs, err := m.dockerBaseArgs(target)
	if err != nil {
		m.finishWithError(jobCtx, jobID, rt, logger, "Invalid target configuration: "+err.Error(), 1)
		return
	}

	if err := m.ensureRunnerImage(jobCtx, dockerBaseArgs); err != nil {
		m.finishWithError(jobCtx, jobID, rt, logger, "Playwright runner image not available: "+err.Error(), 1)
		return
	}

	baseURL := strings.TrimSpace(req.BaseURL)
	if baseURL == "" {
		baseURL = detectBaseURL()
	}
	if baseURL != "" {
		logger("BASE_URL: " + baseURL)
	}

	testPaths, testCount, err := resolveTestSelection(rt.ProjectDir, req.TestFiles)
	if err != nil {
		m.finishWithError(jobCtx, jobID, rt, logger, err.Error(), 1)
		return
	}

	logger(fmt.Sprintf("Project directory: %s", rt.ProjectDir))
	logger(fmt.Sprintf("Total test files: %d", testCount))

	if err := resetDir(filepath.Join(rt.ProjectDir, "playwright-report")); err != nil {
		m.finishWithError(jobCtx, jobID, rt, logger, "Failed to prepare playwright-report: "+err.Error(), 1)
		return
	}
	if err := resetDir(filepath.Join(rt.ProjectDir, "test-results")); err != nil {
		m.finishWithError(jobCtx, jobID, rt, logger, "Failed to prepare test-results: "+err.Error(), 1)
		return
	}

	settings := m.loadWrapperSettings(jobCtx, req.ProjectID, logger)
	wrapperPath, cleanupWrapper, err := writeWrapperConfig(rt.ProjectDir, settings)
	if err != nil {
		m.finishWithError(jobCtx, jobID, rt, logger, "Failed to create wrapper config: "+err.Error(), 1)
		return
	}
	defer cleanupWrapper()
	logger("Wrapper config created: " + wrapperPath)
	logger(fmt.Sprintf(
		"Wrapper settings: reporters=%t(html:%t,list:%t,json:%t,junit:%t), recording=%t(video:%s,screenshot:%s,trace:%s), execution=%t(retries:%d,workers:%d,timeout:%d)",
		settings.OverrideReporters,
		settings.Reporters.HTML,
		settings.Reporters.List,
		settings.Reporters.JSON,
		settings.Reporters.JUnit,
		settings.OverrideRecording,
		settings.Recording.Video,
		settings.Recording.Screenshot,
		settings.Recording.Trace,
		settings.OverrideExecution,
		settings.Execution.Retries,
		settings.Execution.Workers,
		settings.Execution.Timeout,
	))

	mountSource := m.projectMountSource(rt.ProjectDir)
	args := buildPlaywrightDockerArgs(dockerBaseArgs, jobID, mountSource, m.runnerImage, baseURL, testPaths)
	logger("Running Playwright tests in Docker...")

	emitter := newOutputEmitter(m.client, jobID)
	exitCode, runErr := runCommandStreaming(jobCtx, args, logger, emitter)

	status := "completed"
	errMsg := ""
	if runErr != nil {
		errMsg = runErr.Error()
		status = "failed"
	}
	if exitCode != 0 {
		status = "failed"
		if errMsg == "" {
			errMsg = fmt.Sprintf("Command exited with code %d", exitCode)
		}
	}
	if m.isCanceled(jobID) {
		status = "cancelled"
		if errMsg == "" {
			errMsg = "Cancelled"
		}
	}

	if copyErr := copyArtifacts(rt.ProjectDir, rt.ArchivePath, logger); copyErr != nil {
		logger("WARNING: failed to copy artifacts: " + copyErr.Error())
	}

	if videoPath, videoErr := findFirstVideo(filepath.Join(rt.ProjectDir, "test-results")); videoErr == nil && videoPath != "" {
		_ = writeTextFile(rt.LegacyResultFile, videoPath)
	}

	_ = writeTextFile(filepath.Join(rt.ArchivePath, "testcount.txt"), strconv.Itoa(testCount))
	_ = writeTextFile(filepath.Join(rt.ArchivePath, "exitcode.txt"), strconv.Itoa(exitCode))
	_ = writeTextFile(filepath.Join(rt.ArchivePath, "endtime.txt"), formatTimestamp(time.Now().UTC()))
	_ = writeTextFile(rt.StatusFile, status)
	_ = writeTextFile(rt.LegacyStatusFile, status)

	if status == "completed" {
		logger("Playwright execution finished successfully")
	} else {
		logger("Playwright execution finished with errors: " + errMsg)
	}

	if m.client != nil {
		updateCtx, updateCancel := context.WithTimeout(context.Background(), 10*time.Second)
		defer updateCancel()
		if err := orchestrator.EmitJobUpdate(updateCtx, m.client, jobID, status, exitCode, errMsg); err != nil {
			log.Printf("playwright job update failed: %v", err)
		}
	}
}

func (m *Manager) finishWithError(ctx context.Context, jobID string, rt jobRuntime, logger func(string), message string, exitCode int) {
	logger("ERROR: " + message)
	_ = writeTextFile(filepath.Join(rt.ArchivePath, "exitcode.txt"), strconv.Itoa(exitCode))
	_ = writeTextFile(filepath.Join(rt.ArchivePath, "endtime.txt"), formatTimestamp(time.Now().UTC()))
	_ = writeTextFile(rt.StatusFile, "failed")
	_ = writeTextFile(rt.LegacyStatusFile, "failed")

	if m.client != nil {
		updateCtx, cancel := context.WithTimeout(ctx, 10*time.Second)
		defer cancel()
		_ = orchestrator.EmitJobOutput(updateCtx, m.client, jobID, "ERROR: "+message+"\n")
		_ = orchestrator.EmitJobUpdate(updateCtx, m.client, jobID, "failed", exitCode, message)
	}
}

func (m *Manager) resolveProject(projectID string) (string, string, error) {
	projectID = strings.TrimSpace(projectID)
	legacyPlaywrightRoot := filepath.Join(m.appDir, "playwright")
	playwrightProjectsRoot := filepath.Join(m.appDir, "data", "playwright", "projects")

	if projectID == "" || projectID == "_default" {
		if !isDir(legacyPlaywrightRoot) {
			return "", "", fmt.Errorf("project directory not found: %s", legacyPlaywrightRoot)
		}
		return legacyPlaywrightRoot, "default", nil
	}

	if !projectIDPattern.MatchString(projectID) {
		return "", "", errors.New("invalid project ID")
	}

	projectDir := filepath.Join(playwrightProjectsRoot, projectID)
	if isDir(projectDir) {
		return projectDir, projectID, nil
	}

	legacyProjectDir := filepath.Join(legacyPlaywrightRoot, projectID)
	if isDir(legacyProjectDir) {
		return legacyProjectDir, projectID, nil
	}

	return "", "", fmt.Errorf("project directory not found: %s", projectDir)
}

func (m *Manager) resolveTarget(ctx context.Context, targetID string) (*model.Target, error) {
	if targetID == "" {
		return nil, nil
	}
	if m.configStore == nil {
		return nil, errors.New("configuration store unavailable")
	}

	bundle, err := m.configStore.GetBundle(ctx)
	if err != nil {
		return nil, fmt.Errorf("failed to load configuration: %w", err)
	}
	target := bundle.TargetByID[targetID]
	if target == nil {
		return nil, fmt.Errorf("target not found: %s", targetID)
	}
	if target.Type != "local" && target.Type != "docker" {
		return nil, fmt.Errorf("unsupported Playwright target type: %s", target.Type)
	}
	if err := orchestrator.AssertTargetVerified(target); err != nil {
		return nil, err
	}
	return target, nil
}

func (m *Manager) loadWrapperSettings(ctx context.Context, projectID string, logger func(string)) wrapperSettings {
	settings := defaultWrapperSettings()
	if m.client == nil {
		return settings
	}

	normalizedProjectID := strings.TrimSpace(projectID)
	if normalizedProjectID == "" {
		normalizedProjectID = "_default"
	}

	project, err := m.client.FetchPlaywrightProject(ctx, normalizedProjectID)
	if err != nil {
		logger("WARNING: Failed to load Playwright wrapper config: " + err.Error())
		return settings
	}

	rawWrapper, ok := project["wrapperConfig"].(map[string]interface{})
	if !ok || rawWrapper == nil {
		return settings
	}
	mergeWrapperSettings(&settings, rawWrapper)
	return settings
}

func mergeWrapperSettings(settings *wrapperSettings, raw map[string]interface{}) {
	if settings == nil || raw == nil {
		return
	}

	if value, ok := raw["overrideReporters"]; ok {
		settings.OverrideReporters = boolFromAny(value, settings.OverrideReporters)
	}
	if value, ok := raw["overrideRecording"]; ok {
		settings.OverrideRecording = boolFromAny(value, settings.OverrideRecording)
	}
	if value, ok := raw["overrideExecution"]; ok {
		settings.OverrideExecution = boolFromAny(value, settings.OverrideExecution)
	}

	if reporters, ok := raw["reporters"].(map[string]interface{}); ok {
		if value, exists := reporters["html"]; exists {
			settings.Reporters.HTML = boolFromAny(value, settings.Reporters.HTML)
		}
		if value, exists := reporters["list"]; exists {
			settings.Reporters.List = boolFromAny(value, settings.Reporters.List)
		}
		if value, exists := reporters["json"]; exists {
			settings.Reporters.JSON = boolFromAny(value, settings.Reporters.JSON)
		}
		if value, exists := reporters["junit"]; exists {
			settings.Reporters.JUnit = boolFromAny(value, settings.Reporters.JUnit)
		}
	}

	if recording, ok := raw["recording"].(map[string]interface{}); ok {
		if value, exists := recording["video"]; exists {
			settings.Recording.Video = stringFromAny(value, settings.Recording.Video)
		}
		if value, exists := recording["screenshot"]; exists {
			settings.Recording.Screenshot = stringFromAny(value, settings.Recording.Screenshot)
		}
		if value, exists := recording["trace"]; exists {
			settings.Recording.Trace = stringFromAny(value, settings.Recording.Trace)
		}
	}

	if execution, ok := raw["execution"].(map[string]interface{}); ok {
		if value, exists := execution["retries"]; exists {
			settings.Execution.Retries = intFromAny(value, settings.Execution.Retries)
		}
		if value, exists := execution["workers"]; exists {
			settings.Execution.Workers = intFromAny(value, settings.Execution.Workers)
		}
		if value, exists := execution["timeout"]; exists {
			settings.Execution.Timeout = intFromAny(value, settings.Execution.Timeout)
		}
	}
}

func boolFromAny(value interface{}, fallback bool) bool {
	switch typed := value.(type) {
	case bool:
		return typed
	case int:
		return typed != 0
	case int64:
		return typed != 0
	case float64:
		return typed != 0
	case string:
		switch strings.ToLower(strings.TrimSpace(typed)) {
		case "1", "true", "yes", "on":
			return true
		case "0", "false", "no", "off":
			return false
		}
	}
	return fallback
}

func intFromAny(value interface{}, fallback int) int {
	switch typed := value.(type) {
	case int:
		return typed
	case int64:
		return int(typed)
	case float64:
		return int(typed)
	case string:
		parsed, err := strconv.Atoi(strings.TrimSpace(typed))
		if err == nil {
			return parsed
		}
	}
	return fallback
}

func stringFromAny(value interface{}, fallback string) string {
	switch typed := value.(type) {
	case string:
		if strings.TrimSpace(typed) != "" {
			return typed
		}
	}
	return fallback
}

func (m *Manager) dockerBaseArgs(target *model.Target) ([]string, error) {
	args := []string{"docker"}
	if target == nil {
		return args, nil
	}

	conn := map[string]interface{}(target.Connection)
	if socket, ok := conn["socket"].(string); ok && strings.TrimSpace(socket) != "" {
		args = append(args, "-H", "unix://"+strings.TrimSpace(socket))
		return args, nil
	}

	if host, ok := conn["host"].(string); ok && strings.TrimSpace(host) != "" {
		port := 2375
		switch value := conn["port"].(type) {
		case float64:
			port = int(value)
		case int:
			port = value
		case string:
			parsed, err := strconv.Atoi(strings.TrimSpace(value))
			if err == nil && parsed > 0 {
				port = parsed
			}
		}
		args = append(args, "-H", fmt.Sprintf("tcp://%s:%d", strings.TrimSpace(host), port))
	}

	return args, nil
}

func (m *Manager) ensureRunnerImage(ctx context.Context, dockerBaseArgs []string) error {
	inspectArgs := append(append([]string{}, dockerBaseArgs...), "image", "inspect", m.runnerImage)
	if err := exec.CommandContext(ctx, inspectArgs[0], inspectArgs[1:]...).Run(); err != nil {
		return errors.New("image not found: " + m.runnerImage)
	}
	return nil
}

func (m *Manager) createArchive(jobID, projectLabel string, testFiles []string, testName, projectDir string) (jobRuntime, error) {
	archiveDir := filepath.Join(m.appDir, "playwright-reports-archive")
	jobsDir := filepath.Join(m.appDir, "playwright-jobs")
	if err := os.MkdirAll(archiveDir, 0o755); err != nil {
		return jobRuntime{}, err
	}
	if err := os.MkdirAll(jobsDir, 0o755); err != nil {
		return jobRuntime{}, err
	}

	ts := formatTimestamp(time.Now().UTC())
	archivePath := filepath.Join(archiveDir, "report-"+ts)
	if err := os.MkdirAll(archivePath, 0o755); err != nil {
		return jobRuntime{}, err
	}

	rt := jobRuntime{
		ArchivePath:      archivePath,
		OutputFile:       filepath.Join(archivePath, "output.log"),
		StatusFile:       filepath.Join(archivePath, "status.txt"),
		LegacyStatusFile: filepath.Join(jobsDir, jobID+".status"),
		LegacyResultFile: filepath.Join(jobsDir, jobID+".result"),
		ProjectDir:       projectDir,
		StartTimestamp:   ts,
	}

	testFileLabel := "all"
	if len(testFiles) > 0 {
		testFileLabel = strings.Join(testFiles, ",")
	}

	files := map[string]string{
		rt.StatusFile: "running",
		filepath.Join(archivePath, "testname.txt"):  testName,
		filepath.Join(archivePath, "project.txt"):   projectLabel,
		filepath.Join(archivePath, "testfiles.txt"): testFileLabel,
		filepath.Join(archivePath, "jobid.txt"):     jobID,
		filepath.Join(archivePath, "starttime.txt"): ts,
		filepath.Join(archivePath, "testcount.txt"): "0",
		filepath.Join(archivePath, "exitcode.txt"):  "",
		rt.LegacyStatusFile:                         "running",
	}
	for path, content := range files {
		if err := writeTextFile(path, content); err != nil {
			return jobRuntime{}, err
		}
	}

	header := []string{
		"=== Starting Playwright Test (Go Orchestrator) ===",
		"Job ID: " + jobID,
		"Project: " + projectLabel,
		"Test Files: " + testFileLabel,
		"Test Name: " + testName,
		"Archive: " + archivePath,
		"Started at: " + time.Now().Format(time.RFC3339),
		"",
	}
	for _, line := range header {
		if err := appendLine(rt.OutputFile, line); err != nil {
			return jobRuntime{}, err
		}
	}

	return rt, nil
}

func resolveTestSelection(projectDir string, requested []string) ([]string, int, error) {
	if len(requested) > 0 {
		selected := make([]string, 0, len(requested))
		for _, raw := range requested {
			name := strings.TrimSpace(raw)
			if name == "" {
				continue
			}
			if strings.Contains(name, "..") || strings.HasPrefix(name, "/") {
				continue
			}
			name = strings.TrimPrefix(strings.ReplaceAll(name, "\\", "/"), "./")
			candidatePaths := []string{name}
			if !strings.HasPrefix(name, "tests/") {
				candidatePaths = append(candidatePaths, filepath.ToSlash(filepath.Join("tests", name)))
			}
			for _, containerPath := range candidatePaths {
				hostPath := filepath.Join(projectDir, filepath.FromSlash(containerPath))
				if fileExists(hostPath) {
					selected = append(selected, containerPath)
					break
				}
			}
		}
		if len(selected) == 0 {
			return nil, 0, errors.New("no valid test files found")
		}
		return selected, len(selected), nil
	}

	tests, err := discoverPlaywrightTests(projectDir)
	if err != nil {
		return nil, 0, err
	}
	if len(tests) == 0 {
		return nil, 0, errors.New("no test files found")
	}
	return nil, len(tests), nil
}

func detectBaseURL() string {
	candidates := []string{
		"http://host.docker.internal:8099",
		"http://host.docker.internal:3000",
		"http://localhost:8099",
		"http://localhost:3000",
	}
	client := http.Client{Timeout: 2 * time.Second}
	for _, candidate := range candidates {
		resp, err := client.Get(candidate)
		if err != nil {
			continue
		}
		_ = resp.Body.Close()
		return candidate
	}
	return "http://host.docker.internal:8099"
}

func writeWrapperConfig(projectDir string, settings wrapperSettings) (string, func(), error) {
	wrapperPath := filepath.Join(projectDir, "doki-wrapper.config.cjs")
	original := findPlaywrightConfigFile(projectDir)

	reportersJS := buildReportersJS(settings.Reporters)
	overrideReportersJS := strconv.FormatBool(settings.OverrideReporters)
	overrideRecordingJS := strconv.FormatBool(settings.OverrideRecording)
	overrideExecutionJS := strconv.FormatBool(settings.OverrideExecution)
	executionRetries := strconv.Itoa(settings.Execution.Retries)
	executionWorkers := strconv.Itoa(settings.Execution.Workers)
	executionTimeout := strconv.Itoa(settings.Execution.Timeout)
	recordingVideo := jsString(settings.Recording.Video)
	recordingScreenshot := jsString(settings.Recording.Screenshot)
	recordingTrace := jsString(settings.Recording.Trace)

	var content string
	if original != "" {
		template := `/**
 * Doki Playwright Wrapper Config
 *
 * Auto-generated by Doki Go Orchestrator.
 * Settings are loaded from project wrapperConfig metadata.
 */
const path = require("path");
const { pathToFileURL } = require("url");

async function loadOriginalConfig() {
  const resolvedConfigPath = path.resolve(__dirname, "__ORIGINAL_CONFIG__");
  try {
    const originalModule = require(resolvedConfigPath);
    return originalModule.default || originalModule;
  } catch (error) {
    if (error && error.code === "ERR_REQUIRE_ESM") {
      const originalModule = await import(pathToFileURL(resolvedConfigPath).href);
      return originalModule.default || originalModule;
    }
    throw error;
  }
}

async function loadConfig() {
  try {
    const originalConfig = await loadOriginalConfig();
    const mergedConfig = { ...originalConfig };

    if (__OVERRIDE_REPORTERS__) {
      mergedConfig.reporter = __REPORTERS_JS__;
    }

    mergedConfig.outputDir = "./test-results";

    if (__OVERRIDE_RECORDING__) {
      mergedConfig.use = {
        ...(originalConfig.use || {}),
        video: __RECORDING_VIDEO__,
        screenshot: __RECORDING_SCREENSHOT__,
        trace: __RECORDING_TRACE__
      };
    }

    if (process.env.BASE_URL) {
      mergedConfig.use = {
        ...(mergedConfig.use || originalConfig.use || {}),
        baseURL: process.env.BASE_URL
      };
    }

    if (__OVERRIDE_EXECUTION__) {
      mergedConfig.retries = __EXECUTION_RETRIES__;
      mergedConfig.workers = __EXECUTION_WORKERS__;
      mergedConfig.timeout = __EXECUTION_TIMEOUT__;
    }

    return mergedConfig;
  } catch (error) {
    return {
      testDir: "./tests",
      reporter: __REPORTERS_JS__,
      outputDir: "./test-results",
      use: {
        trace: __RECORDING_TRACE__,
        screenshot: __RECORDING_SCREENSHOT__,
        video: __RECORDING_VIDEO__
      }
    };
  }
}

module.exports = loadConfig();
`
		content = strings.NewReplacer(
			"__ORIGINAL_CONFIG__", original,
			"__OVERRIDE_REPORTERS__", overrideReportersJS,
			"__REPORTERS_JS__", reportersJS,
			"__OVERRIDE_RECORDING__", overrideRecordingJS,
			"__RECORDING_VIDEO__", recordingVideo,
			"__RECORDING_SCREENSHOT__", recordingScreenshot,
			"__RECORDING_TRACE__", recordingTrace,
			"__OVERRIDE_EXECUTION__", overrideExecutionJS,
			"__EXECUTION_RETRIES__", executionRetries,
			"__EXECUTION_WORKERS__", executionWorkers,
			"__EXECUTION_TIMEOUT__", executionTimeout,
		).Replace(template)
	} else {
		testDir := "./tests"
		if !isDir(filepath.Join(projectDir, "tests")) {
			testDir = "."
		}

		template := `/**
 * Doki Playwright Standalone Config
 *
 * Auto-generated by Doki Go Orchestrator because no playwright.config was found.
 */
module.exports = {
  testDir: __TEST_DIR__,
  testMatch: "**/*.@(spec|test).?(c|m)[jt]s?(x)",
  fullyParallel: true,
  forbidOnly: !!process.env.CI,

  retries: __OVERRIDE_EXECUTION__ ? __EXECUTION_RETRIES__ : 0,
  workers: __OVERRIDE_EXECUTION__ ? __EXECUTION_WORKERS__ : undefined,
  timeout: __OVERRIDE_EXECUTION__ ? __EXECUTION_TIMEOUT__ : 30000,

  reporter: __REPORTERS_JS__,
  outputDir: "./test-results",

  use: {
    baseURL: process.env.BASE_URL,
    trace: __RECORDING_TRACE__,
    screenshot: __RECORDING_SCREENSHOT__,
    video: __RECORDING_VIDEO__
  },

  projects: [
    {
      name: "chromium",
      use: { browserName: "chromium" }
    }
  ]
};
`
		content = strings.NewReplacer(
			"__TEST_DIR__", jsString(testDir),
			"__OVERRIDE_EXECUTION__", overrideExecutionJS,
			"__EXECUTION_RETRIES__", executionRetries,
			"__EXECUTION_WORKERS__", executionWorkers,
			"__EXECUTION_TIMEOUT__", executionTimeout,
			"__REPORTERS_JS__", reportersJS,
			"__RECORDING_TRACE__", recordingTrace,
			"__RECORDING_SCREENSHOT__", recordingScreenshot,
			"__RECORDING_VIDEO__", recordingVideo,
		).Replace(template)
	}

	if err := writeTextFile(wrapperPath, content); err != nil {
		return "", nil, err
	}
	cleanup := func() {
		_ = os.Remove(wrapperPath)
	}
	return wrapperPath, cleanup, nil
}

func buildReportersJS(reporters wrapperReporters) string {
	items := make([]string, 0, 4)
	if reporters.HTML {
		items = append(items, `["html", { outputFolder: "./playwright-report", open: "never" }]`)
	}
	if reporters.List {
		items = append(items, `["list"]`)
	}
	if reporters.JSON {
		items = append(items, `["json", { outputFile: "./test-results/results.json" }]`)
	}
	if reporters.JUnit {
		items = append(items, `["junit", { outputFile: "./test-results/results.xml" }]`)
	}
	return "[" + strings.Join(items, ",") + "]"
}

func findPlaywrightConfigFile(projectDir string) string {
	for _, name := range playwrightConfigFiles {
		if fileExists(filepath.Join(projectDir, name)) {
			return name
		}
	}
	return ""
}

func discoverPlaywrightTests(projectDir string) ([]string, error) {
	tests := []string{}
	skipDirs := map[string]struct{}{
		".git":              {},
		"node_modules":      {},
		".cache":            {},
		"test-results":      {},
		"playwright-report": {},
	}

	err := filepath.WalkDir(projectDir, func(path string, d fs.DirEntry, walkErr error) error {
		if walkErr != nil {
			return walkErr
		}
		if path != projectDir {
			if _, skip := skipDirs[d.Name()]; skip {
				if d.IsDir() {
					return filepath.SkipDir
				}
				return nil
			}
		}
		if d.IsDir() {
			return nil
		}
		if !playwrightTestFilePattern.MatchString(d.Name()) {
			return nil
		}

		rel, err := filepath.Rel(projectDir, path)
		if err != nil {
			return err
		}
		tests = append(tests, filepath.ToSlash(rel))
		return nil
	})
	if err != nil {
		return nil, err
	}

	sort.Strings(tests)
	return tests, nil
}

func jsString(value string) string {
	return strconv.Quote(value)
}

func buildPlaywrightDockerArgs(base []string, jobID, projectDir, image, baseURL string, testPaths []string) []string {
	args := append([]string{}, base...)
	args = append(args,
		"run",
		"--rm",
		"--name", "playwright-"+sanitizeName(jobID),
		"--label", "doki.job.id="+jobID,
		"--network", "host",
		"--add-host", "host.docker.internal:host-gateway",
		"-v", projectDir+":/tests:rw",
		"-w", "/tests",
		"-e", "PLAYWRIGHT_HTML_OPEN=never",
		"-e", "CI=true",
	)
	if strings.TrimSpace(baseURL) != "" {
		args = append(args, "-e", "BASE_URL="+strings.TrimSpace(baseURL))
	}
	args = append(args, image, "test")
	args = append(args, testPaths...)
	args = append(args, "--config=doki-wrapper.config.cjs")
	return args
}

func (m *Manager) projectMountSource(projectDir string) string {
	if m.hostAppDir == "" {
		return projectDir
	}
	cleanProject := filepath.Clean(projectDir)
	cleanApp := filepath.Clean(m.appDir)
	if cleanProject == cleanApp {
		return m.hostAppDir
	}
	prefix := cleanApp + string(filepath.Separator)
	if strings.HasPrefix(cleanProject, prefix) {
		suffix := strings.TrimPrefix(cleanProject, prefix)
		return filepath.Join(m.hostAppDir, suffix)
	}
	return projectDir
}

func runCommandStreaming(ctx context.Context, args []string, logger func(string), emitter *outputEmitter) (int, error) {
	if len(args) == 0 {
		return 1, errors.New("empty command")
	}

	cmd := exec.CommandContext(ctx, args[0], args[1:]...)
	stdout, err := cmd.StdoutPipe()
	if err != nil {
		return 1, err
	}
	stderr, err := cmd.StderrPipe()
	if err != nil {
		return 1, err
	}

	if err := cmd.Start(); err != nil {
		return 1, err
	}

	done := make(chan struct{})
	go func() {
		ticker := time.NewTicker(1 * time.Second)
		defer ticker.Stop()
		for {
			select {
			case <-ticker.C:
				emitter.Flush()
			case <-done:
				return
			}
		}
	}()

	var wg sync.WaitGroup
	readPipe := func(prefix string, reader io.ReadCloser) {
		defer wg.Done()
		defer reader.Close()

		scanner := bufio.NewScanner(reader)
		scanner.Buffer(make([]byte, 64*1024), maxScannerLineSize)
		for scanner.Scan() {
			line := scanner.Text()
			if prefix != "" {
				line = prefix + line
			}
			logger(line)
			emitter.Append(line + "\n")
		}
		if scanErr := scanner.Err(); scanErr != nil {
			msg := "stream read error: " + scanErr.Error()
			logger(msg)
			emitter.Append(msg + "\n")
		}
	}

	wg.Add(2)
	go readPipe("", stdout)
	go readPipe("[stderr] ", stderr)

	waitErr := cmd.Wait()
	wg.Wait()
	close(done)
	emitter.Flush()

	if waitErr == nil {
		return 0, nil
	}
	if errors.Is(ctx.Err(), context.DeadlineExceeded) {
		return 124, errors.New("command timed out")
	}
	if exitErr, ok := waitErr.(*exec.ExitError); ok {
		return exitErr.ExitCode(), fmt.Errorf("command exited with code %d", exitErr.ExitCode())
	}
	return 1, waitErr
}

func copyArtifacts(projectDir, archivePath string, logger func(string)) error {
	reportDir := filepath.Join(projectDir, "playwright-report")
	if isDir(reportDir) {
		logger("Copying Playwright HTML report")
		if err := copyDir(reportDir, archivePath); err != nil {
			return err
		}
	}

	resultsDir := filepath.Join(projectDir, "test-results")
	if isDir(resultsDir) {
		logger("Copying test-results artifacts")
		if err := copyDir(resultsDir, filepath.Join(archivePath, "test-results")); err != nil {
			return err
		}
	}
	return nil
}

func findFirstVideo(root string) (string, error) {
	first := ""
	err := filepath.WalkDir(root, func(path string, d fs.DirEntry, walkErr error) error {
		if walkErr != nil {
			return walkErr
		}
		if d.IsDir() {
			return nil
		}
		if strings.EqualFold(filepath.Ext(d.Name()), ".webm") {
			first = path
			return io.EOF
		}
		return nil
	})
	if errors.Is(err, io.EOF) {
		return first, nil
	}
	if err != nil {
		return "", err
	}
	return first, nil
}

func (m *Manager) findLegacyVideoPath(jobID string) string {
	if rt, ok := m.getRuntime(jobID); ok {
		if fileExists(rt.LegacyResultFile) {
			b, _ := os.ReadFile(rt.LegacyResultFile)
			return strings.TrimSpace(string(b))
		}
	}
	return ""
}

func resetDir(path string) error {
	if err := os.RemoveAll(path); err != nil {
		return err
	}
	return os.MkdirAll(path, 0o755)
}

func copyDir(src, dst string) error {
	if err := os.MkdirAll(dst, 0o755); err != nil {
		return err
	}

	return filepath.WalkDir(src, func(path string, d fs.DirEntry, walkErr error) error {
		if walkErr != nil {
			return walkErr
		}
		rel, err := filepath.Rel(src, path)
		if err != nil {
			return err
		}
		target := filepath.Join(dst, rel)
		if d.IsDir() {
			return os.MkdirAll(target, 0o755)
		}
		data, err := os.ReadFile(path)
		if err != nil {
			return err
		}
		return os.WriteFile(target, data, 0o644)
	})
}

func logHeader(logger func(string), jobID string, req RunRequest, archivePath string) {
	project := strings.TrimSpace(req.ProjectID)
	if project == "" {
		project = "default"
	}
	logger("Job ID: " + jobID)
	logger("Project: " + project)
	if len(req.TestFiles) == 0 {
		logger("Test Files: all")
	} else {
		logger("Test Files: " + strings.Join(req.TestFiles, ","))
	}
	logger("Test Name: " + req.TestName)
	logger("Archive: " + archivePath)
	logger("")
}

func formatTimestamp(ts time.Time) string {
	millis := ts.Nanosecond() / int(time.Millisecond)
	return ts.Format("2006-01-02T15-04-05") + fmt.Sprintf("-%03dZ", millis)
}

func generatePlaywrightJobID() string {
	buf := make([]byte, 4)
	_, _ = rand.Read(buf)
	return fmt.Sprintf("playwright-%s-%x", time.Now().Format("20060102-150405"), buf)
}

func sanitizeName(value string) string {
	safe := strings.ToLower(value)
	safe = strings.Map(func(r rune) rune {
		if (r >= 'a' && r <= 'z') || (r >= '0' && r <= '9') || r == '-' || r == '_' {
			return r
		}
		return '-'
	}, safe)
	safe = strings.Trim(safe, "-")
	if len(safe) > 63 {
		safe = safe[:63]
	}
	if safe == "" {
		return "playwright"
	}
	return safe
}

func writeTextFile(path, content string) error {
	return os.WriteFile(path, []byte(content), 0o644)
}

func appendLine(path, line string) error {
	f, err := os.OpenFile(path, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0o644)
	if err != nil {
		return err
	}
	defer f.Close()
	_, err = f.WriteString(line + "\n")
	return err
}

func effectiveTestFiles(items []string) []string {
	if len(items) == 0 {
		return []string{"all"}
	}
	return items
}

func readString(values map[string]interface{}, key string) string {
	value, ok := values[key]
	if !ok || value == nil {
		return ""
	}
	switch typed := value.(type) {
	case string:
		return typed
	case fmt.Stringer:
		return typed.String()
	default:
		return fmt.Sprint(value)
	}
}

func isDir(path string) bool {
	info, err := os.Stat(path)
	return err == nil && info.IsDir()
}

func fileExists(path string) bool {
	info, err := os.Stat(path)
	return err == nil && !info.IsDir()
}

func (m *Manager) setRuntime(jobID string, rt jobRuntime) {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.running[jobID] = rt
}

func (m *Manager) getRuntime(jobID string) (jobRuntime, bool) {
	m.mu.RLock()
	defer m.mu.RUnlock()
	rt, ok := m.running[jobID]
	return rt, ok
}

func (m *Manager) clearRuntime(jobID string) {
	m.mu.Lock()
	defer m.mu.Unlock()
	delete(m.running, jobID)
	delete(m.canceled, jobID)
}

func (m *Manager) setCanceled(jobID string, canceled bool) {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.canceled[jobID] = canceled
}

func (m *Manager) isCanceled(jobID string) bool {
	m.mu.RLock()
	defer m.mu.RUnlock()
	return m.canceled[jobID]
}

type outputEmitter struct {
	client *controlplane.Client
	jobID  string

	mu  sync.Mutex
	buf strings.Builder
}

func newOutputEmitter(client *controlplane.Client, jobID string) *outputEmitter {
	return &outputEmitter{client: client, jobID: jobID}
}

func (o *outputEmitter) Append(chunk string) {
	o.mu.Lock()
	defer o.mu.Unlock()
	o.buf.WriteString(chunk)
	if o.buf.Len() >= 4096 {
		o.flushLocked()
	}
}

func (o *outputEmitter) Flush() {
	o.mu.Lock()
	defer o.mu.Unlock()
	o.flushLocked()
}

func (o *outputEmitter) flushLocked() {
	if o.client == nil || strings.TrimSpace(o.jobID) == "" || o.buf.Len() == 0 {
		o.buf.Reset()
		return
	}

	payload := o.buf.String()
	o.buf.Reset()

	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	if err := orchestrator.EmitJobOutput(ctx, o.client, o.jobID, payload); err != nil {
		log.Printf("playwright output event failed: %v", err)
	}
}
