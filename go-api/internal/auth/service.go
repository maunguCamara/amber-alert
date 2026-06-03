package auth

import (
	"errors"
	"fmt"
	"time"

	"github.com/golang-jwt/jwt/v5"
	"github.com/google/uuid"
	"github.com/kenya-amber-alert/api/internal/models"
	"golang.org/x/crypto/bcrypt"
)

var (
	ErrInvalidToken = errors.New("invalid or expired token")
	ErrUnauthorized = errors.New("unauthorized")
)

type Claims struct {
	UserID uuid.UUID        `json:"uid"`
	Role   models.UserRole  `json:"role"`
	County string           `json:"county,omitempty"`
	jwt.RegisteredClaims
}

type Service struct {
	secret     []byte
	accessTTL  time.Duration
	refreshTTL time.Duration
}

func NewService(secret string, accessTTL, refreshTTL time.Duration) *Service {
	return &Service{
		secret:     []byte(secret),
		accessTTL:  accessTTL,
		refreshTTL: refreshTTL,
	}
}

// IssueTokens creates a new access + refresh token pair.
func (s *Service) IssueTokens(user *models.User) (accessToken, refreshToken string, err error) {
	accessToken, err = s.sign(user, s.accessTTL, "access")
	if err != nil {
		return "", "", fmt.Errorf("sign access token: %w", err)
	}
	refreshToken, err = s.sign(user, s.refreshTTL, "refresh")
	if err != nil {
		return "", "", fmt.Errorf("sign refresh token: %w", err)
	}
	return
}

func (s *Service) sign(user *models.User, ttl time.Duration, tokenType string) (string, error) {
	now := time.Now()
	claims := Claims{
		UserID: user.ID,
		Role:   user.Role,
		County: user.County,
		RegisteredClaims: jwt.RegisteredClaims{
			Subject:   user.ID.String(),
			IssuedAt:  jwt.NewNumericDate(now),
			ExpiresAt: jwt.NewNumericDate(now.Add(ttl)),
			Issuer:    "amber-alert-ke",
			ID:        tokenType + ":" + uuid.NewString(),
		},
	}
	return jwt.NewWithClaims(jwt.SigningMethodHS256, claims).SignedString(s.secret)
}

// VerifyAccess parses and validates an access token, returning the claims.
func (s *Service) VerifyAccess(tokenStr string) (*Claims, error) {
	return s.verify(tokenStr)
}

func (s *Service) verify(tokenStr string) (*Claims, error) {
	token, err := jwt.ParseWithClaims(tokenStr, &Claims{}, func(t *jwt.Token) (interface{}, error) {
		if _, ok := t.Method.(*jwt.SigningMethodHMAC); !ok {
			return nil, fmt.Errorf("unexpected signing method: %v", t.Header["alg"])
		}
		return s.secret, nil
	})
	if err != nil || !token.Valid {
		return nil, ErrInvalidToken
	}
	claims, ok := token.Claims.(*Claims)
	if !ok {
		return nil, ErrInvalidToken
	}
	return claims, nil
}

// HashPassword hashes a plain-text password using bcrypt.
func HashPassword(plain string) (string, error) {
	b, err := bcrypt.GenerateFromPassword([]byte(plain), bcrypt.DefaultCost)
	return string(b), err
}

// CheckPassword compares a plain password against a bcrypt hash.
func CheckPassword(hash, plain string) bool {
	return bcrypt.CompareHashAndPassword([]byte(hash), []byte(plain)) == nil
}