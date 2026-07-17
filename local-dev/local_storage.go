// local_storage.go
// Drop this file into go-api/internal/repository/ to replace S3 uploads with
// local filesystem writes during development.
//
// To activate: set AMBER_S3_ENDPOINT="" (empty) in your config.yaml / .env.go.
// The Go API will detect the empty endpoint and call NewLocalStorage() instead.
//
// Files are written to /tmp/amber-alert-media/{case_id}/
// and served via a simple file server registered on /dev-media/

package repository

import (
	"bytes"
	"context"
	"fmt"
	"image/jpeg"
	_ "image/png"
	"image"
	"os"
	"path/filepath"
	"time"

	"github.com/google/uuid"
	"golang.org/x/image/draw"
)

const localMediaRoot = "/tmp/amber-alert-media"

type LocalStorage struct {
	root    string
	baseURL string // e.g. http://localhost:8080/dev-media
}

func NewLocalStorage(serverPort int) *LocalStorage {
	root := localMediaRoot
	_ = os.MkdirAll(root, 0755)
	return &LocalStorage{
		root:    root,
		baseURL: fmt.Sprintf("http://localhost:%d/dev-media", serverPort),
	}
}

func (s *LocalStorage) UploadPhoto(_ context.Context, caseID uuid.UUID, filename string, data []byte) (*UploadResult, error) {
	caseDir := filepath.Join(s.root, caseID.String())
	if err := os.MkdirAll(filepath.Join(caseDir, "photos"), 0755); err != nil {
		return nil, err
	}
	if err := os.MkdirAll(filepath.Join(caseDir, "thumbs"), 0755); err != nil {
		return nil, err
	}

	// Save original
	ts := fmt.Sprintf("%d", time.Now().UnixMilli())
	origName := ts + "_" + sanitiseFilename(filename)
	origPath := filepath.Join(caseDir, "photos", origName)
	if err := os.WriteFile(origPath, data, 0644); err != nil {
		return nil, fmt.Errorf("write original: %w", err)
	}

	// Generate thumbnail
	thumbName := ts + "_thumb.jpg"
	thumbPath := filepath.Join(caseDir, "thumbs", thumbName)
	if thumb, err := generateThumbnail(data, 200, 200); err == nil {
		_ = os.WriteFile(thumbPath, thumb, 0644)
	}

	return &UploadResult{
		URL:      fmt.Sprintf("%s/%s/photos/%s", s.baseURL, caseID, origName),
		ThumbURL: fmt.Sprintf("%s/%s/thumbs/%s", s.baseURL, caseID, thumbName),
		MimeType: mimeFromExt(filepath.Ext(filename)),
		Size:     int64(len(data)),
	}, nil
}

func (s *LocalStorage) Delete(_ context.Context, key string) error {
	path := filepath.Join(s.root, key)
	return os.Remove(path)
}

func (s *LocalStorage) GetSignedURL(_ context.Context, key string) (string, error) {
	return fmt.Sprintf("%s/%s", s.baseURL, key), nil
}

func sanitiseFilename(name string) string {
	safe := ""
	for _, ch := range name {
		if (ch >= 'a' && ch <= 'z') || (ch >= 'A' && ch <= 'Z') ||
			(ch >= '0' && ch <= '9') || ch == '.' || ch == '-' || ch == '_' {
			safe += string(ch)
		}
	}
	if safe == "" {
		return "photo.jpg"
	}
	return safe
}

// generateThumbnail is duplicated here to keep local_storage.go self-contained.
func generateThumbnailLocal(data []byte, w, h int) ([]byte, error) {
	src, _, err := image.Decode(bytes.NewReader(data))
	if err != nil {
		return nil, err
	}
	dst := image.NewRGBA(image.Rect(0, 0, w, h))
	draw.CatmullRom.Scale(dst, dst.Bounds(), src, src.Bounds(), draw.Over, nil)
	var buf bytes.Buffer
	if err := jpeg.Encode(&buf, dst, &jpeg.Options{Quality: 80}); err != nil {
		return nil, err
	}
	return buf.Bytes(), nil
}
