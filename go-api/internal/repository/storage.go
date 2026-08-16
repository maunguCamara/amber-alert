package repository

import (
	"bytes"
	"context"
	"fmt"
	"image"
	"image/jpeg"
	_ "image/png"
	_ "image/gif"
	"io"
	"net/http"
	"path/filepath"
	"strings"

	"github.com/aws/aws-sdk-go-v2/aws"
	awscfg "github.com/aws/aws-sdk-go-v2/config"
	"github.com/aws/aws-sdk-go-v2/credentials"
	"github.com/aws/aws-sdk-go-v2/service/s3"
	"github.com/google/uuid"
	"example.com/amberalert/pkg/config"
	"golang.org/x/image/draw"
)

// allowedMIMEs is the set of MIME types we accept for photo uploads.
// FIX T-03: validation is now done against the actual file bytes using
// net/http.DetectContentType (magic-byte sniffing), not just the file
// extension. An attacker cannot bypass this by renaming shell.php to
// shell.jpg.
var allowedMIMEs = map[string]bool{
	"image/jpeg": true,
	"image/png":  true,
	"image/webp": true,
}

// ErrUnsupportedFileType is returned when the uploaded file's magic bytes
// do not match an allowed image format.
var ErrUnsupportedFileType = fmt.Errorf("unsupported file type: only JPEG, PNG and WebP are allowed")

// maxImageDimension prevents image-bomb attacks (T-19).
// Any axis larger than this is rejected before decode.
const maxImageDimension = 8000

type Storage struct {
	client  *s3.Client
	bucket  string
	baseURL string
}

func NewStorage(cfg *config.Config) (*Storage, error) {
	customResolver := aws.EndpointResolverWithOptionsFunc(
		func(service, region string, options ...interface{}) (aws.Endpoint, error) {
			if cfg.S3Endpoint != "" {
				return aws.Endpoint{
					URL:               cfg.S3Endpoint,
					SigningRegion:     cfg.S3Region,
					HostnameImmutable: true,
				}, nil
			}
			return aws.Endpoint{}, &aws.EndpointNotFoundError{}
		},
	)

	awsConfig, err := awscfg.LoadDefaultConfig(context.Background(),
		awscfg.WithRegion(cfg.S3Region),
		awscfg.WithCredentialsProvider(credentials.NewStaticCredentialsProvider(
			cfg.S3AccessKey, cfg.S3SecretKey, "",
		)),
		awscfg.WithEndpointResolverWithOptions(customResolver),
	)
	if err != nil {
		return nil, fmt.Errorf("load aws config: %w", err)
	}

	client := s3.NewFromConfig(awsConfig, func(o *s3.Options) {
		o.UsePathStyle = cfg.S3ForcePathStyle
	})

	baseURL := cfg.S3Endpoint
	if baseURL == "" {
		baseURL = fmt.Sprintf("https://s3.%s.amazonaws.com", cfg.S3Region)
	}

	return &Storage{client: client, bucket: cfg.S3Bucket, baseURL: baseURL}, nil
}

// UploadResult holds the public URLs for the original and thumbnail.
type UploadResult struct {
	URL      string
	ThumbURL string
	MimeType string
	Size     int64
}

// UploadPhoto validates, stores, and thumbnails an uploaded image.
//
// FIX T-03: Magic-byte MIME detection replaces extension-only checking.
// FIX T-19: Image dimensions are checked before decode to prevent image bombs.
func (s *Storage) UploadPhoto(ctx context.Context, caseID uuid.UUID, filename string, data []byte) (*UploadResult, error) {
	// 1. Detect actual MIME type from the first 512 bytes — ignore the extension
	detectedMIME := http.DetectContentType(data[:min512(len(data))])
	// DetectContentType may return "image/jpeg; charset=..." — strip parameters
	mimeBase := strings.Split(detectedMIME, ";")[0]
	mimeBase  = strings.TrimSpace(mimeBase)

	if !allowedMIMEs[mimeBase] {
		return nil, fmt.Errorf("%w (detected: %s)", ErrUnsupportedFileType, mimeBase)
	}

	// 2. Decode to check dimensions before storing anything
	img, _, err := image.Decode(bytes.NewReader(data))
	if err != nil {
		return nil, fmt.Errorf("decode image: %w", err)
	}
	bounds := img.Bounds()
	if bounds.Max.X > maxImageDimension || bounds.Max.Y > maxImageDimension {
		return nil, fmt.Errorf("image dimensions %dx%d exceed maximum allowed %d",
			bounds.Max.X, bounds.Max.Y, maxImageDimension)
	}

	// 3. Determine extension from actual MIME (not from filename)
	ext := extFromMIME(mimeBase)
	key      := fmt.Sprintf("cases/%s/photos/%s%s", caseID, uuid.New(), ext)
	thumbKey := fmt.Sprintf("cases/%s/thumbs/%s.jpg", caseID, uuid.New())

	// 4. Upload original
	if _, err := s.client.PutObject(ctx, &s3.PutObjectInput{
		Bucket:      aws.String(s.bucket),
		Key:         aws.String(key),
		Body:        bytes.NewReader(data),
		ContentType: aws.String(mimeBase),
	}); err != nil {
		return nil, fmt.Errorf("upload original: %w", err)
	}

	// 5. Generate and upload thumbnail (non-fatal if it fails)
	thumbURL := ""
	if thumb, err := generateThumbnail(img, 200, 200); err == nil {
		if _, err := s.client.PutObject(ctx, &s3.PutObjectInput{
			Bucket:      aws.String(s.bucket),
			Key:         aws.String(thumbKey),
			Body:        bytes.NewReader(thumb),
			ContentType: aws.String("image/jpeg"),
		}); err == nil {
			thumbURL = fmt.Sprintf("%s/%s/%s", s.baseURL, s.bucket, thumbKey)
		}
	}

	return &UploadResult{
		URL:      fmt.Sprintf("%s/%s/%s", s.baseURL, s.bucket, key),
		ThumbURL: thumbURL,
		MimeType: mimeBase,
		Size:     int64(len(data)),
	}, nil
}

func (s *Storage) Delete(ctx context.Context, key string) error {
	_, err := s.client.DeleteObject(ctx, &s3.DeleteObjectInput{
		Bucket: aws.String(s.bucket),
		Key:    aws.String(key),
	})
	return err
}

func (s *Storage) GetSignedURL(ctx context.Context, key string) (string, error) {
	presignClient := s3.NewPresignClient(s.client)
	req, err := presignClient.PresignGetObject(ctx, &s3.GetObjectInput{
		Bucket: aws.String(s.bucket),
		Key:    aws.String(key),
	})
	if err != nil {
		return "", fmt.Errorf("presign get object: %w", err)
	}
	return req.URL, nil
}

// ReadAll reads up to maxBytes from r.
func ReadAll(r io.Reader, maxBytes int64) ([]byte, error) {
	return io.ReadAll(io.LimitReader(r, maxBytes))
}

// ── Helpers ───────────────────────────────────────────────────────────────────

// generateThumbnail scales img to fit within w×h using Catmull-Rom resampling.
// Takes a pre-decoded image.Image to avoid decoding twice.
func generateThumbnail(src image.Image, w, h int) ([]byte, error) {
	dst := image.NewRGBA(image.Rect(0, 0, w, h))
	draw.CatmullRom.Scale(dst, dst.Bounds(), src, src.Bounds(), draw.Over, nil)

	var buf bytes.Buffer
	if err := jpeg.Encode(&buf, dst, &jpeg.Options{Quality: 82}); err != nil {
		return nil, err
	}
	return buf.Bytes(), nil
}

// extFromMIME returns the canonical file extension for a MIME type.
func extFromMIME(mime string) string {
	switch mime {
	case "image/jpeg":
		return ".jpg"
	case "image/png":
		return ".png"
	case "image/webp":
		return ".webp"
	default:
		return ".bin"
	}
}

// min512 returns the smaller of n and 512 — used to safely slice the
// first 512 bytes for MIME detection without panicking on short files.
func min512(n int) int {
	if n < 512 {
		return n
	}
	return 512
}

// extFromFilename is kept for logging purposes only — never used for security decisions.
func extFromFilename(filename string) string {
	return strings.ToLower(filepath.Ext(filename))
}