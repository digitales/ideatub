# Example: Vehicle Valuation API Specification

**Version**: 1.0
**Status**: Draft
**Last Updated**: 2025-01-15

## Overview
This specification defines the API endpoint for retrieving vehicle valuations based on VIN and location data.

## Requirements

### Functional Requirements
- Accept VIN (Vehicle Identification Number) as input
- Accept optional location/postcode for regional market data
- Return vehicle valuation with confidence score
- Support multiple valuation sources (trade, retail, market)
- Include vehicle details (make, model, year) in response

### Non-Functional Requirements
- Response time: < 2 seconds for 95th percentile
- Support concurrent requests: 100+ requests/second
- Cache results for 24 hours
- Rate limit: 100 requests per minute per user

## Acceptance Criteria
- [ ] API accepts VIN in standard format (17 characters)
- [ ] API validates VIN format and returns 400 for invalid input
- [ ] API returns 404 when vehicle not found
- [ ] API returns valuation data in JSON format
- [ ] Response includes all required fields (valuation, confidence, vehicle details)
- [ ] Caching works correctly and respects TTL
- [ ] Rate limiting prevents abuse

## User Stories
- As an API consumer, I want to get vehicle valuations by VIN so that I can display pricing information
- As an API consumer, I want location-based valuations so that I can show regional market differences
- As a system administrator, I want rate limiting so that the API is protected from abuse

## API Endpoint

### POST /api/vehicles/valuation

**Request Body**:
```json
{
  "vin": "1HGBH41JXMN109186",
  "postcode": "SW1A 1AA"
}
```

**Response (200 OK)**:
```json
{
  "vin": "1HGBH41JXMN109186",
  "vehicle": {
    "make": "Honda",
    "model": "Civic",
    "year": 2021
  },
  "valuations": {
    "trade": 15000,
    "retail": 18000,
    "market": 16500
  },
  "confidence": 0.95,
  "currency": "GBP",
  "last_updated": "2025-01-15T10:30:00Z"
}
```

**Error Responses**:
- `400 Bad Request`: Invalid VIN format
- `404 Not Found`: Vehicle not found
- `429 Too Many Requests`: Rate limit exceeded

## Technical Constraints
- Must integrate with existing vehicle data services
- Must use Laravel validation for input
- Must respect existing authentication/authorization

## Dependencies
- Vehicle data service (`DvlaService`)
- Market data service (`MarketDataService`)
- Vehicle valuation service (`VehicleValuationService`)

## References
- [Architectural Decision: Queue System](/decisions/2025-01-15-example-decision-record.md)
- [Implementation Notes: Vehicle Valuation Service](/dev/example-implementation-notes.md)