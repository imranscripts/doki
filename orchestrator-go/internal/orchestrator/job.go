package orchestrator

import (
	"crypto/rand"
	"fmt"
	"time"
)

func GenerateJobID() string {
	buf := make([]byte, 4)
	_, _ = rand.Read(buf)
	return fmt.Sprintf("job-%s-%x", time.Now().Format("20060102-150405"), buf)
}
