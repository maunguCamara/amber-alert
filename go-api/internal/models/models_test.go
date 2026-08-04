package models_test

import (
	"encoding/json"
	"testing"
	"time"

	"github.com/google/uuid"
	"localhost/amberalert/internal/models"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// ── CaseStatus ────────────────────────────────────────────────────────────────

func TestCaseStatus_ValidValues(t *testing.T) {
	valid := []models.CaseStatus{
		models.CaseStatusActive,
		models.CaseStatusReview,
		models.CaseStatusResolved,
		models.CaseStatusClosed,
	}
	for _, s := range valid {
		assert.NotEmpty(t, string(s))
	}
}

func TestCaseStatus_JSONRoundtrip(t *testing.T) {
	type payload struct {
		Status models.CaseStatus `json:"status"`
	}
	original := payload{Status: models.CaseStatusActive}
	data, err := json.Marshal(original)
	require.NoError(t, err)

	var decoded payload
	require.NoError(t, json.Unmarshal(data, &decoded))
	assert.Equal(t, original.Status, decoded.Status)
}

// ── Gender ────────────────────────────────────────────────────────────────────

func TestGender_ValidValues(t *testing.T) {
	for _, g := range []models.Gender{models.GenderMale, models.GenderFemale, models.GenderUnknown} {
		assert.NotEmpty(t, string(g))
	}
}

// ── UserRole ──────────────────────────────────────────────────────────────────

func TestUserRole_HierarchyValues(t *testing.T) {
	roles := []models.UserRole{
		models.RolePublic,
		models.RoleOfficer,
		models.RoleAdmin,
		models.RoleSuperAdmin,
	}
	seen := make(map[models.UserRole]bool)
	for _, r := range roles {
		assert.False(t, seen[r], "duplicate role value: %s", r)
		seen[r] = true
	}
}

// ── Case ──────────────────────────────────────────────────────────────────────

func TestCase_JSONOmitsPasswordHash(t *testing.T) {
	u := models.User{
		ID:           uuid.New(),
		Email:        "test@example.ke",
		FullName:     "Test User",
		Role:         models.RolePublic,
		PasswordHash: "bcrypt$secret",
	}
	data, err := json.Marshal(u)
	require.NoError(t, err)
	assert.NotContains(t, string(data), "bcrypt$secret", "password hash must not appear in JSON")
	assert.NotContains(t, string(data), "password_hash")
}

func TestCase_JSONIncludesRequiredFields(t *testing.T) {
	caseID := uuid.New()
	c := models.Case{
		ID:           caseID,
		ReferenceNo:  "KE-2024-00001",
		ChildName:    "Brian Otieno",
		Age:          8,
		Gender:       models.GenderMale,
		Status:       models.CaseStatusActive,
		County:       "Nairobi",
		LastSeenArea: "Mathare",
		MissingSince: time.Now(),
	}
	data, err := json.Marshal(c)
	require.NoError(t, err)

	var decoded map[string]json.RawMessage
	require.NoError(t, json.Unmarshal(data, &decoded))

	for _, field := range []string{"id", "reference_no", "child_name", "age", "status", "county"} {
		assert.Contains(t, decoded, field, "field %q missing from JSON", field)
	}
}

func TestCase_ResolvedAtIsNilByDefault(t *testing.T) {
	c := models.Case{}
	assert.Nil(t, c.ResolvedAt)
}

// ── CaseGeoPoint ─────────────────────────────────────────────────────────────

func TestCaseGeoPoint_ValidKenyaCoordinates(t *testing.T) {
	// All Kenyan county centroids should be within the bounding box.
	points := []models.CaseGeoPoint{
		{Lat: -1.286, Lng: 36.817, County: "Nairobi"},
		{Lat: -4.043, Lng: 39.668, County: "Mombasa"},
		{Lat: -0.092, Lng: 34.768, County: "Kisumu"},
		{Lat: 3.120,  Lng: 35.597, County: "Turkana"},
		{Lat: -4.667, Lng: 39.200, County: "Kwale"},
	}
	for _, p := range points {
		assert.GreaterOrEqual(t, p.Lat, -5.0,  "%s lat too far south", p.County)
		assert.LessOrEqual(t,    p.Lat,  5.0,  "%s lat too far north", p.County)
		assert.GreaterOrEqual(t, p.Lng, 34.0,  "%s lng too far west", p.County)
		assert.LessOrEqual(t,    p.Lng, 42.0,  "%s lng too far east", p.County)
	}
}

func TestCaseGeoPoint_JSONRoundtrip(t *testing.T) {
	original := models.CaseGeoPoint{
		ID:          uuid.New(),
		ReferenceNo: "KE-2024-00042",
		ChildName:   "Grace Wanjiku",
		Age:         7,
		Gender:      models.GenderFemale,
		Status:      models.CaseStatusActive,
		County:      "Kisumu",
		Lat:         -0.092,
		Lng:         34.768,
		MissingSince: time.Now().Truncate(time.Second),
	}

	data, err := json.Marshal(original)
	require.NoError(t, err)

	var decoded models.CaseGeoPoint
	require.NoError(t, json.Unmarshal(data, &decoded))

	assert.Equal(t, original.ID, decoded.ID)
	assert.Equal(t, original.ChildName, decoded.ChildName)
	assert.Equal(t, original.County, decoded.County)
	assert.InDelta(t, original.Lat, decoded.Lat, 0.0001)
	assert.InDelta(t, original.Lng, decoded.Lng, 0.0001)
}

// ── WSEvent ───────────────────────────────────────────────────────────────────

func TestWSEvent_TypeField(t *testing.T) {
	for _, evType := range []string{"case.new", "case.updated", "case.resolved"} {
		e := models.WSEvent{Type: evType, Payload: "{}"}
		data, err := json.Marshal(e)
		require.NoError(t, err)
		assert.Contains(t, string(data), evType)
	}
}

// ── BroadcastChannel ─────────────────────────────────────────────────────────

func TestBroadcastChannel_ValidValues(t *testing.T) {
	channels := []models.BroadcastChannel{
		models.ChannelSMS,
		models.ChannelWhatsApp,
		models.ChannelEmail,
		models.ChannelWebPush,
	}
	seen := make(map[models.BroadcastChannel]bool)
	for _, ch := range channels {
		assert.False(t, seen[ch], "duplicate channel: %s", ch)
		seen[ch] = true
	}
}