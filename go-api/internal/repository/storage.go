package repository

import (
	"bytes"
	"context"
	"fmt"
	"image"
	"image/jpeg"
	_ "image/png"
	"io"
	"path/filepath"
	"strings"

	"github.com/aws/aws-sdk-go-v2/aws"
	awscfg "github.com/aws/aws-sdk-go-v2/config"
	"github.com/aws/aws-sdk-go-v2/credentials"
	"github.com/aws/aws-sdk-go-v2/service/s3"
	"github.com/google/uuid"
	"github.com/kenya-amber-alert/api/pkg/config"
	"golang.org/x/image/draw"
)

type Storage struct {
	client *s3.Client
	bucket string
	// Public base URL for generating download links
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

	awsCfg, err := awscfg.LoadDefaultConfig(context.Background(),
		awscfg.WithRegion(cfg.S3Region),
		awscfg.WithCredentialsProvider(credentials.NewStaticCredentialsProvider(
			cfg.S3AccessKey, cfg.S3SecretKey, "",
		)),
		awscfg.WithEndpointResolverWithOptions(customResolver),
	)
	if err != nil {
		return nil, fmt.Errorf("load aws config: %w", err)
	}

	client := s3.NewFromConfig(awsCfg, func(o *s3.Options) {
		o.UsePathStyle = cfg.S3ForcePathStyle
	})

	baseURL := cfg.S3Endpoint
	if baseURL == "" {
		baseURL = fmt.Sprintf("https://s3.%s.amazonaws.com", cfg.S3Region)
	}

	return &Storage{
		client:  client,
		bucket:  cfg.S3Bucket,
		baseURL: baseURL,
	}, nil
}

// UploadResult holds URLs for original and thumbnail.
type UploadResult struct {
	URL      string
	ThumbURL string
	MimeType string
	Size     int64
}

// UploadPhoto stores the original image and generates a 200×200 thumbnail.
func (s *Storage) UploadPhoto(ctx context.Context, caseID uuid.UUID, filename string, data []byte) (*UploadResult, error) {
	ext := strings.ToLower(filepath.Ext(filename))
	if ext == "" {
		ext = ".jpg"
	}
	key := fmt.Sprintf("cases/%s/photos/%s%s", caseID, uuid.New(), ext)
	thumbKey := fmt.Sprintf("cases/%s/thumbs/%s%s", caseID, uuid.New(), ext)
	mime := mimeFromExt(ext)

	// Upload original
	if _, err := s.client.PutObject(ctx, &s3.PutObjectInput{
		Bucket:      aws.String(s.bucket),
		Key:         aws.String(key),
		Body:        bytes.NewReader(data),
		ContentType: aws.String(mime),
	}); err != nil {
		return nil, fmt.Errorf("upload original: %w", err)
	}

	// Generate and upload thumbnail
	thumbData, err := generateThumbnail(data, 200, 200)
	if err == nil {
		_, _ = s.client.PutObject(ctx, &s3.PutObjectInput{
			Bucket:      aws.String(s.bucket),
			Key:         aws.String(thumbKey),
			Body:        bytes.NewReader(thumbData),
			ContentType: aws.String("image/jpeg"),
		})
	}

	return &UploadResult{
		URL:      fmt.Sprintf("%s/%s/%s", s.baseURL, s.bucket, key),
		ThumbURL: fmt.Sprintf("%s/%s/%s", s.baseURL, s.bucket, thumbKey),
		MimeType: mime,
		Size:     int64(len(data)),
	}, nil
}

func generateThumbnail(data []byte, w, h int) ([]byte, error) {
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
		return "", err
	}
	return req.URL, nil
}

func mimeFromExt(ext string) string {
	switch ext {
	case ".jpg", ".jpeg":
		return "image/jpeg"
	case ".png":
		return "image/png"
	case ".webp":
		return "image/webp"
	default:
		return "application/octet-stream"
	}
}

// ReadAll reads multipart file into bytes, respecting size limit.
func ReadAll(r io.Reader, maxBytes int64) ([]byte, error) {
	return io.ReadAll(io.LimitReader(r, maxBytes))
}