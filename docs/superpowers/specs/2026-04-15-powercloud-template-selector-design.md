# PowerCloud Manual Site Creation — Template Selector

**Date:** 2026-04-15
**Status:** Approved

## Problem

The PowerCloud (新架構) manual site creation form in `power-partner` only allows selecting a package and entering an email. Unlike `nestjs-helm-admin`, it has no template selection — users cannot choose a website template when manually creating a site.

## Solution

Add an optional template selector dropdown to the `PowercloudOpenSite` component. The backend already supports `templateUrl` in the POST /wordpress request body — only the frontend form needs updating.

## Scope

**Single file change:** `js/src/pages/AdminApp/Dashboard/ManualSiteSync/index.tsx`

No PHP backend changes required.

## Design

### Template Data Fetching

Add a `useQuery` call in `PowercloudOpenSite` to fetch templates from the PowerCloud API:

```
GET /templates/wordpress?page=1&limit=250
```

Using the existing `powerCloudInstance` (same axios instance and pattern used for packages).

Response shape: `{ data: Website[] }` where each Website has:
- `id: string`
- `primaryDomain: string`
- `domain?: string`
- `subDomain?: string`
- `wildcardDomain: string`

### UI Changes

Add an optional `<Select>` field between "選擇方案" and "開站完成後信息寄送Email":

- **Label:** "網站模板"
- **Placeholder:** "請選擇網站模板"
- **Helper text:** "選擇一個模板來創建網站"
- **Required:** No (`allowClear` enabled)
- **Search:** `showSearch` with case-insensitive `filterOption` on domain text
- **Options:** Each template displayed by its domain (`primaryDomain` with fallbacks)
- **Disabled** while form is submitting (`isPending`)

### Form Submission

In `handleFinish()`, if a template is selected:

1. Find the selected template object from the fetched list by ID
2. Resolve the template URL: `primaryDomain ?? domain ?? subDomain ?? wildcardDomain`
3. Include `templateUrl` in the POST /wordpress request body

If no template is selected, omit `templateUrl` (creates a blank site).

### Data Flow

```
useQuery('/templates/wordpress') → IPowercloudTemplate[]
    ↓
<Select> dropdown (optional, searchable, clearable)
    ↓
handleFinish()
    ↓
Resolve templateUrl from selected template's domain fields
    ↓
POST /wordpress { packageId, namespace, wildcardDomain, mysql, wordpress, templateUrl? }
```

### Type Definition

```typescript
interface IPowercloudTemplate {
  id: string
  primaryDomain: string
  domain?: string
  subDomain?: string
  wildcardDomain: string
}
```

## What Stays the Same

- PHP backend (`FetchPowerCloud::site_sync()`) — already supports `templateUrl`
- WPCD (舊架構) form — untouched
- Package selection flow — untouched
- Email sending flow — untouched
