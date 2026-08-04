package auth_test

import (
	"testing"
	"time"

	"github.com/google/uuid"
	"localhost/amberalert/internal/auth"
	"localhost/amberalert/internal/models"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// ── helpers ───────────────────────────────────────────────────────────────────

func newSvc(t *testing.T) *auth.Service {
	t.Helper()
	return auth.NewService("super-secret-test-key-32-bytes!!", 15*time.Minute, 24*time.Hour)
}

func publicUser() *models.User {
	return &models.User{
		ID:     uuid.MustParse("11111111-1111-1111-1111-111111111111"),
		Email:  "citizen@example.ke",
		Role:   models.RolePublic,
		County: "",
	}
}

func officerUser() *models.User {
	return &models.User{
		ID:     uuid.MustParse("22222222-2222-2222-2222-222222222222"),
		Email:  "officer@dci.go.ke",
		Role:   models.RoleOfficer,
		County: "Nairobi",
	}
}

// ── IssueTokens ───────────────────────────────────────────────────────────────

func TestIssueTokens_ReturnsNonEmptyPair(t *testing.T) {
	svc := newSvc(t)
	access, refresh, err := svc.IssueTokens(publicUser())

	require.NoError(t, err)
	assert.NotEmpty(t, access)
	assert.NotEmpty(t, refresh)
	assert.NotEqual(t, access, refresh, "access and refresh tokens must differ")
}

func TestIssueTokens_DifferentUsersGetDifferentTokens(t *testing.T) {
	svc := newSvc(t)
	a1, _, _ := svc.IssueTokens(publicUser())
	a2, _, _ := svc.IssueTokens(officerUser())
	assert.NotEqual(t, a1, a2)
}

// ── VerifyAccess ──────────────────────────────────────────────────────────────

func TestVerifyAccess_ValidToken_ReturnsClaims(t *testing.T) {
	svc := newSvc(t)
	user := officerUser()
	access, _, err := svc.IssueTokens(user)
	require.NoError(t, err)

	claims, err := svc.VerifyAccess(access)
	require.NoError(t, err)

	assert.Equal(t, user.ID, claims.UserID)
	assert.Equal(t, models.RoleOfficer, claims.Role)
	assert.Equal(t, "Nairobi", claims.County)
	assert.Equal(t, "amber-alert-ke", claims.Issuer)
}

func TestVerifyAccess_EmptyToken_ReturnsError(t *testing.T) {
	svc := newSvc(t)
	_, err := svc.VerifyAccess("")
	assert.ErrorIs(t, err, auth.ErrInvalidToken)
}

func TestVerifyAccess_MalformedToken_ReturnsError(t *testing.T) {
	svc := newSvc(t)
	_, err := svc.VerifyAccess("not.a.jwt")
	assert.ErrorIs(t, err, auth.ErrInvalidToken)
}

func TestVerifyAccess_WrongSecret_ReturnsError(t *testing.T) {
	svcA := auth.NewService("secret-A-32-bytes-long-exactly!!", 15*time.Minute, 24*time.Hour)
	svcB := auth.NewService("secret-B-32-bytes-long-exactly!!", 15*time.Minute, 24*time.Hour)

	token, _, err := svcA.IssueTokens(publicUser())
	require.NoError(t, err)

	_, err = svcB.VerifyAccess(token)
	assert.ErrorIs(t, err, auth.ErrInvalidToken)
}

func TestVerifyAccess_ExpiredToken_ReturnsError(t *testing.T) {
	// TTL of -1 second means the token is already expired when issued
	svc := auth.NewService("super-secret-test-key-32-bytes!!", -time.Second, 24*time.Hour)
	token, _, err := svc.IssueTokens(publicUser())
	require.NoError(t, err)

	_, err = svc.VerifyAccess(token)
	assert.ErrorIs(t, err, auth.ErrInvalidToken)
}

func TestVerifyAccess_TamperedPayload_ReturnsError(t *testing.T) {
	svc := newSvc(t)
	token, _, _ := svc.IssueTokens(publicUser())

	// Flip a character in the payload segment (middle part of JWT)
	parts := splitToken(token)
	require.Len(t, parts, 3)
	parts[1] = parts[1] + "X"
	tampered := parts[0] + "." + parts[1] + "." + parts[2]

	_, err := svc.VerifyAccess(tampered)
	assert.ErrorIs(t, err, auth.ErrInvalidToken)
}

// ── Claims content ────────────────────────────────────────────────────────────

func TestClaims_PublicUserHasNoCounty(t *testing.T) {
	svc := newSvc(t)
	token, _, _ := svc.IssueTokens(publicUser())
	claims, err := svc.VerifyAccess(token)
	require.NoError(t, err)
	assert.Empty(t, claims.County)
}

func TestClaims_OfficerHasCounty(t *testing.T) {
	svc := newSvc(t)
	token, _, _ := svc.IssueTokens(officerUser())
	claims, err := svc.VerifyAccess(token)
	require.NoError(t, err)
	assert.Equal(t, "Nairobi", claims.County)
}

// ── Password hashing ──────────────────────────────────────────────────────────

func TestHashPassword_ProducesNonEmptyHash(t *testing.T) {
	hash, err := auth.HashPassword("StrongPassword123!")
	require.NoError(t, err)
	assert.NotEmpty(t, hash)
	assert.NotEqual(t, "StrongPassword123!", hash)
}

func TestHashPassword_TwoCallsDifferentSalts(t *testing.T) {
	h1, _ := auth.HashPassword("password")
	h2, _ := auth.HashPassword("password")
	assert.NotEqual(t, h1, h2, "bcrypt salts must differ")
}

func TestCheckPassword_CorrectPassword_ReturnsTrue(t *testing.T) {
	hash, _ := auth.HashPassword("correct-horse-battery-staple")
	assert.True(t, auth.CheckPassword(hash, "correct-horse-battery-staple"))
}

func TestCheckPassword_WrongPassword_ReturnsFalse(t *testing.T) {
	hash, _ := auth.HashPassword("correct-horse-battery-staple")
	assert.False(t, auth.CheckPassword(hash, "wrong-password"))
}

func TestCheckPassword_EmptyPassword_ReturnsFalse(t *testing.T) {
	hash, _ := auth.HashPassword("some-password")
	assert.False(t, auth.CheckPassword(hash, ""))
}

func TestCheckPassword_EmptyHash_ReturnsFalse(t *testing.T) {
	assert.False(t, auth.CheckPassword("", "password"))
}

// ── helper ────────────────────────────────────────────────────────────────────

func splitToken(token string) []string {
	var parts []string
	start := 0
	for i, ch := range token {
		if ch == '.' {
			parts = append(parts, token[start:i])
			start = i + 1
		}
	}
	parts = append(parts, token[start:])
	return parts
}av