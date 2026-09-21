# lsm-web implementation map for Part 3 (HardeningCard, useHardening, API client, i18n)

- **Repo:** `/Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-web`. Every path below is relative to it unless it is absolute.
- **Spec:** `/Users/bmarkovic/Documents/Projects/LSMPlatform/lsm-api/docs/superpowers/specs/2026-09-21-htaccess-hardening-design.md`. Part 3 is lines 188-205; the web test line is 216.
- **Installed versions:**
  - antd 5.29.3 (package.json `^5.22.0`)
  - @tanstack/react-query 5.90.21
  - i18next 25.8.5
  - react-i18next 16.5.4
  - TypeScript 5.9.3
  - React 18.3, Vite 6, zustand 5, dayjs, axios 1.7

---

## 1. `src/features/projects/components/sections/SecuritySection.tsx` (879 lines)

**Props, line 70-72.** The project is untyped:
```tsx
interface SecuritySectionProps {
  project: any;
}
```

**Component head, line 82-90.** The file has no `useTranslation`; every string is hardcoded English:
```tsx
export default function SecuritySection({ project }: SecuritySectionProps) {
  const { resolvedTheme } = useThemeStore();
  const isDark = resolvedTheme === 'dark';
  const { message } = App.useApp();
  const queryClient = useQueryClient();
  const hasLsmConnection = !!project.has_health_check_secret;
  const [updatingSetting, setUpdatingSetting] = useState<string | null>(null);
```
- `hasLsmConnection` is derived at line 87 from `project.has_health_check_secret`.
- That field is typed in `packages/types/src/index.ts:92` as `has_health_check_secret?: boolean;`.

**Mounting.** `src/features/projects/pages/ProjectDetailPageV2.tsx:252` sets `const commonProps = { project };`. Line 283-284 renders `case 'security': return <SecuritySection {...commonProps} />;`. No role or ability props are passed down.

**Queries, line 93-125.** All follow the pattern below. The other three are:
- `health`, line 93-98
- `securityHeaders`, line 109-114, `staleTime: 60000`
- `securityHeaderSnippets`, line 120-125, `enabled: hasLsmConnection && snippetModalOpen`
```tsx
  // Fetch security settings
  const { data: securitySettings, isLoading: isLoadingSettings } = useQuery({
    queryKey: queryKeys.projects.securitySettings(project.id),
    queryFn: () => api.lsm.getSecuritySettings(project.id).then(r => r.data?.data || r.data),
    enabled: hasLsmConnection,
    staleTime: 30000,
  });
```

**Mutation and `handleToggleSetting`, line 128-147, full text:**
```tsx
  // Update security setting mutation
  const updateSettingMutation = useMutation({
    mutationFn: (settings: Record<string, boolean>) =>
      api.lsm.updateSecuritySettings(project.id, settings),
    onSuccess: (_, variables) => {
      const settingName = Object.keys(variables)[0];
      message.success(`${settingName.replace('_', ' ')} updated`);
      queryClient.invalidateQueries({ queryKey: queryKeys.projects.detail(project.id) });
      setUpdatingSetting(null);
    },
    onError: (error: any) => {
      message.error(error?.message || 'Failed to update setting');
      setUpdatingSetting(null);
    },
  });

  const handleToggleSetting = (key: string, value: boolean) => {
    setUpdatingSetting(key);
    updateSettingMutation.mutate({ [key]: value });
  };
```
- Invalidating `queryKeys.projects.detail(id)` = `['projects', id]` prefix-matches every `['projects', id, …]` key, including the security ones. This is documented in `queryKeys.ts:4-7`.
- The existing toggle never checks `success` in the response body. The hardening mutations must not copy that; spec line 159-161 requires an explicit `success` check.

**Early returns.** All hooks are declared before them.
- Line 150-157: `if (!hasLsmConnection) return <Empty …description="Connect WordPress to enable security scanning" />`.
- Line 160-169: `if (isLoading) return <Spin…>`.
- Anything placed in the main JSX therefore only renders when the site is connected and health data has loaded.

**Order of the main JSX, line 261-878:**
1. Header with Refresh button, 264-269
2. Critical alert, 272-281
3. Score card, 284-362
4. "Security Checks" card, 365-403
5. "Security Controls" card, 406-615
6. "HTTP Security Headers" card, 618-721
7. Snippets `<Modal>`, 724-846
8. "Outdated Plugins" card, 849-876

**One full Switch row, line 586-612.** This is the last row in Security Controls:
```tsx
            {/* Enable Security Headers */}
            <div style={{ 
              display: 'flex', 
              justifyContent: 'space-between', 
              alignItems: 'center',
              padding: '16px 0',
              borderTop: `1px solid ${isDark ? 'rgba(255,255,255,0.1)' : 'rgba(0,0,0,0.1)'}`,
            }}>
              <div style={{ flex: 1 }}>
                <Space>
                  <Text strong>Enable Security Headers</Text>
                  <Tooltip title="Automatically adds X-Frame-Options, X-Content-Type-Options, X-XSS-Protection, Referrer-Policy, Permissions-Policy, and HSTS headers. One-click security hardening." color="#1e293b">
                    <InfoCircleOutlined style={{ color: '#94a3b8', cursor: 'help' }} />
                  </Tooltip>
                </Space>
                <div>
                  <Text type="secondary" style={{ fontSize: 13 }}>
                    Inject all recommended HTTP security headers via PHP
                  </Text>
                </div>
              </div>
              <Switch
                checked={securitySettings?.security_headers_enabled ?? false}
                loading={updatingSetting === 'security_headers_enabled'}
                onChange={(checked) => handleToggleSetting('security_headers_enabled', checked)}
              />
            </div>
```
- Earlier rows (420-446 and the ones after it) use `borderBottom: \`1px solid ${isDark ? '#334155' : '#e2e8f0'}\`` as the separator.
- The read-only row at 560-584 replaces the Switch with a status Tag. That is the template for a status-tag row:
```tsx
              <Tag color={securitySettings?.debug_enabled ? 'error' : 'success'} style={{ margin: 0 }}>
                {securitySettings?.debug_enabled ? 'Enabled' : 'Disabled'}
              </Tag>
```

**Card shell to copy, line 406-413:**
```tsx
      <Card
        title="Security Controls"
        style={{
          marginTop: 16,
          borderRadius: 12,
          background: isDark ? '#1e293b' : '#fff',
        }}
      >
```

**Insertion point.** Put it between line 615 (`</Card>` closing Security Controls) and line 617 (`{/* Security Headers */}`). The markup is `<HardeningCard project={project} />`.
- The HTTP Security Headers card at 618-636 has only `style={{ borderColor: … }}`. It has no `marginTop`, no `borderRadius` and no background.
- It will therefore sit flush against whatever is above it. Give HardeningCard `marginTop: 16`. Leave the Headers card alone or add `marginTop: 16` to it; that is the implementer's call.

**Lint.** Unused imports are tolerated. `eslint.config.js` sets `no-unused-vars: 'off'` and tsconfig sets `noUnusedLocals: false`.

---

## 2. API client and query keys

### `src/lib/lsm-api.ts` (523 lines)

**Factory and base path, line 86-89:**
```ts
export function createLsmApi(client: AxiosInstance) {
  const basePath = (projectId: number) => `/projects/${projectId}/lsm`;

  return {
```
- It is wired in `src/lib/api.ts:69` as `lsm: createLsmApi(client),`.
- Components call `api.lsm.*` after `import { api } from '@/lib/api'`.
- The raw client is exported at `api.ts:80`: `export { client as apiClient };`.

**Security settings functions, line 377-409:**
```ts
    /**
     * Get security settings
     */
    getSecuritySettings: (projectId: number) =>
      client.get<{
        success: boolean;
        data: {
          comments_enabled: boolean;
          registration_enabled: boolean;
          xmlrpc_enabled: boolean;
          rest_api_public: boolean;
          file_editing_disabled: boolean;
          debug_enabled: boolean;
          security_headers_enabled: boolean;
        };
      }>(`${basePath(projectId)}/security-settings`),

    /**
     * Update security settings
     */
    updateSecuritySettings: (projectId: number, settings: {
      comments_enabled?: boolean;
      ...
      security_headers_enabled?: boolean;
    }) =>
      client.post<{ success: boolean; message: string; changes: Record<string, boolean> }>(
        `${basePath(projectId)}/security-settings`,
        settings
      ),
```

**Conventions:**
- Each function returns the raw `AxiosResponse`; there is no unwrapping inside the client.
- Callers unwrap with `.then(r => r.data?.data || r.data)` (SecuritySection.tsx:103). `BackupsSection.tsx:62` uses `(r.data as any)?.data || r.data`.
- Response types are inline generics. Named interfaces are exported at the top of the file (`LsmStatus`, `LsmHealth`, … line 9-84).
- Each function has a JSDoc block above it. Sections are separated by `// ====` banners, for example line 333-335 `// SITE INFO & SECURITY SETTINGS`.
- Long-running POSTs pass a per-request timeout, line 166-167:
```ts
    updatePlugin: (projectId: number, slug: string) =>
      client.post<any>(`${basePath(projectId)}/update-plugin`, { slug }, { timeout: 120000 }),
```

**Axios wrapper, `packages/api-client/src/client.ts`:**
- `src/lib/api.ts:39-47` configures the client:
  - `baseURL: import.meta.env.VITE_API_URL || '/api/v1'`
  - a bearer token from `useAuthStore.getState().token`
  - `onUnauthorized` → logout and redirect to `/login`
  - `timeout: 30000`
- The response interceptor is at line 66-81:
```ts
    (error: AxiosError<ApiResponse<unknown>>) => {
      if (error.response?.status === 401) {
        config.onUnauthorized?.();
      }
      // Enhance error with API message if available
      if (error.response?.data?.message) {
        error.message = error.response.data.message;
      }
      return Promise.reject(error);
    }
```
- Any non-2xx response, including the spec's 422, rejects and lands in the mutation's `onError`.
- `error.message` becomes the API's message only if the body has a `message` key.
- `src/lib/apiError.ts:12-32` has the house helper `getApiErrorMessage(error, fallback)`. It reads `response.data.errors` (first field's first message), then `response.data.message`, then the fallback.
- Usage example, `TodoDetailModal.tsx:203`: `onError: (error) => message.error(getApiErrorMessage(error, 'Failed to update todo')),`.

**Types.**
- `@lsm/types` resolves to `packages/types/dist`. It is built with tsup and gitignored (`.gitignore`: `dist/`).
- `tsconfig.json` `paths` only maps `@/*`.
- Put the hardening types in `src/lib/lsm-api.ts` as exported interfaces. Adding them to the package would require rebuilding it everywhere.

### `src/lib/queryKeys.ts` (179 lines)

**Convention, header line 1-9:**
```ts
// Keys nest by ownership: everything belonging to a project lives under
// ['projects', id, …], so invalidating queryKeys.projects.detail(id)
// refreshes all of it via prefix matching. ...
// Never hand-write a key literal outside this file.
```

**Security keys, line 40-47:**
```ts
    // Security
    securitySettings: (id: Id) => ['projects', n(id), 'security', 'settings'] as const,
    securityHeaders: (id: Id) => ['projects', n(id), 'security', 'headers'] as const,
    securityHeaderSnippets: (id: Id) =>
      ['projects', n(id), 'security', 'header-snippets'] as const,
    securityScans: (id: Id) => ['projects', n(id), 'security', 'scans'] as const,
    securityScanLatest: (id: Id) =>
      ['projects', n(id), 'security', 'scan-latest'] as const,
```
- The new key goes after line 47: `securityHardening: (id: Id) => ['projects', n(id), 'security', 'hardening'] as const,`.
- ESLint enforces this (`eslint.config.js`, `no-restricted-syntax`). A raw array literal under `queryKey:`, or a local named `queryKey` initialised with an array, fails with `'Use queryKeys.* from src/lib/queryKeys.ts, not a raw array literal.'`. Lint runs with `--max-warnings 0`.

---

## 3. Hooks pattern

**`src/hooks/useBackupSettings.ts` in full (54 lines):**
```ts
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/lib/api';
import { queryKeys } from '@/lib/queryKeys';

/**
 * Server-side backup configuration as returned by GET /backups/settings.
 * `enabled` is the backup feature master switch (BACKUP_ENABLED on the API);
 * when false every other backup endpoint answers 403 and the UI hides itself.
 */
export interface BackupSettings {
  enabled: boolean;
  driver: string;
  available_drivers: string[];
  retention: {
    max_backups: number;
    max_age_days: number;
    min_backups: number;
  };
  schedule: {
    enabled: boolean;
    frequency: string;
    time: string;
    day_of_week: number;
  };
  defaults: {
    includes_database: boolean;
    includes_files: boolean;
    includes_uploads: boolean;
  };
}

/** Single shared query for the backup config — every consumer dedupes on the same key. */
export function useBackupSettings() {
  return useQuery<BackupSettings>({
    queryKey: queryKeys.settings.backup(),
    queryFn: () => apiClient.get('/backups/settings').then(r => r.data?.data || r.data),
    staleTime: 5 * 60 * 1000,
  });
}

/**
 * Whether the backup feature is switched on for this platform.
 *
 * `enabled` is `undefined` until the server has answered (or the request
 * failed), then a definite boolean. Callers hide backup UI unless it is
 * exactly `true`, and only redirect/fall back once it is exactly `false` —
 * so a page deep-linked to the backups tab shows a spinner rather than
 * flashing Overview while the flag is still loading.
 */
export function useBackupsEnabled(): { enabled: boolean | undefined; isPending: boolean } {
  const { data, isPending } = useBackupSettings();
  if (isPending) return { enabled: undefined, isPending: true };
  return { enabled: data?.enabled === true, isPending: false };
}
```

**Where hooks live:**
- Cross-feature hooks are in `src/hooks/`: `useAiChat.ts`, `useBackupSettings.ts`, `useMediaQuery.ts`, `useTokenRefresh.ts`.
- Feature-specific hooks are in `src/features/<feature>/hooks/`. The only ones today are invalidation helpers:
  - `src/features/projects/hooks/useInvalidateTodos.ts`
  - `src/features/time/hooks/useInvalidateTimeData.ts`
  - `src/features/vault/hooks/useInvalidateCredentials.ts`
- Create `src/features/projects/hooks/useHardening.ts`.

**House style of a feature hook, `useInvalidateTodos.ts` in full:**
```ts
export function useInvalidateTodos() {
  const queryClient = useQueryClient();
  return (projectId: number | string) => {
    queryClient.invalidateQueries({ queryKey: queryKeys.projects.detail(projectId) });
    queryClient.invalidateQueries({ queryKey: queryKeys.todos.all() });
    queryClient.invalidateQueries({ queryKey: queryKeys.dashboard.all() });
  };
}
```
- Hooks use named exports, carry a JSDoc comment explaining why, and export an interface for the payload.
- No existing hook bundles a query with mutations. Mutations are declared inline in components, so `useHardening` would be the first of its kind.

**Component location.**
- `sections/` contains only `*Section.tsx` files (default exports) plus `index.ts`.
- Standalone cards live one level up and use named exports, for example `src/features/projects/components/ConnectWordPressCard.tsx` with `export function ConnectWordPressCard({ project, compact = false })`.
- Put `HardeningCard.tsx` at `src/features/projects/components/HardeningCard.tsx` with a named export.
- Follow the same shape as ConnectWordPressCard line 37-42: `useTranslation`, `App.useApp()`, `useQueryClient`, `useThemeStore`.

---

## 4. UI kit

**antd.** `package.json` has `"antd": "^5.22.0"`, installed 5.29.3. Icons come from `@ant-design/icons ^5.5.0`.

**Provider.** `src/components/ThemeProvider.tsx:22-26` wraps everything in `<ConfigProvider theme={currentTheme}><AntApp>…</AntApp></ConfigProvider>`. `App.useApp()` therefore works everywhere; 60 files use it.

**Confirm dialogs.** The dominant pattern is `const { message, modal } = App.useApp(); modal.confirm({...})`.
- 10 call sites use `modal.confirm`. There is 1 legacy static `Modal.confirm` at `LibraryResourcesPage.tsx:290`.
- `<Popconfirm>` appears in 16 files for small inline deletes, for example `MalwareSection.tsx:648-653` and `ProjectDetailPageV2.tsx:388-395` (the latter with `okButtonProps={{ danger: true }}`).

Danger example with i18n, `src/features/profile/components/IntegrationTokensCard.tsx:36,53-62`:
```tsx
  const { message, modal } = App.useApp();
  ...
  const confirmRevoke = (token: IntegrationToken) => {
    modal.confirm({
      title: t('integrationTokens.revokeConfirm.title', { name: token.name }),
      content: t('integrationTokens.revokeConfirm.content'),
      okText: t('integrationTokens.revokeConfirm.okText'),
      okButtonProps: { danger: true },
      cancelText: t('integrationTokens.revokeConfirm.cancelText'),
      onOk: () => revokeMutation.mutateAsync(token.id),
    });
  };
```
- `onOk: () => mutateAsync(...)` returns a promise, so antd keeps the OK button loading until the request settles. That suits a change that can take up to 120 s.

Example with a warning inside the content, `BackupsSection.tsx:136-151`:
```tsx
    modal.confirm({
      title: 'Restore Backup',
      content: (
        <div>
          <Alert type="warning" message="This will replace your current site content" style={{ marginBottom: 12 }} />
          <Text>Restore backup from {formatDate(backup.created_at)}?</Text>
        </div>
      ),
      okText: 'Restore',
      onOk: () => restoreMutation.mutate(backup.id),
    });
```
- `TeamPage.tsx:288-294` uses the alternative `okType: 'danger'`.
- `modal.confirm` content is rendered once and does not follow component state. For "Pause for download" with a 15/30/60 select, pick one of:
  - Put the `<Select>` next to the button and make the confirm text-only. This is the simplest option.
  - Use a controlled `<Modal open=…>`, as SecuritySection already does at 724-736.
  - Keep the value in a `useRef`.

**Toasts.** Use `message` from `App.useApp()`.
- Typical calls are `message.success(...)`, `.error`, `.info` and `.warning` (SecuritySection.tsx:134,139; BackupsSection.tsx:101).
- antd `notification` is not used anywhere.
- Two legacy files import static `message` from antd (`SetAvailabilityModal.tsx:1`, `share/ui/secure-share.tsx:7`); do not copy that.
- For a long rollback reason, `message.error(text, 8)` (longer duration) is fine.

**Tag and Badge.**
- Tags use preset status colours: `<Tag color="success">`, `"error"` (9 uses each), `"warning"`, `"default"`, `"blue"`, `"purple"`, `"orange"`.
  - Inside rows they carry `style={{ margin: 0 }}` (SecuritySection.tsx:581).
  - In card titles the pattern is `<Space><Icon/><span>Title</span><Tag …/></Space>` (SecuritySection.tsx:620-627).
- `Badge` is only used for counts and dots (`<Badge count=…>`, `<Badge status="success" />`). For rule status use Tag.
- Suggested mapping:
  - on → `success`
  - off → `default`
  - paused → `warning`
  - manual → `blue`
  - unsupported → `error` or `default`, wrapped in `<Tooltip title={reason} color="#1e293b">`. Every tooltip in this file uses `color="#1e293b"`.

**Countdown.** None exists. There is no `Statistic.Countdown` and no `useCountdown` or `useInterval` hook.
- Timers are hand-rolled `setInterval` inside `useEffect`, for example `GdprAuditSection.tsx:154-172`:
```tsx
    const interval = setInterval(() => {
      const elapsed = Math.floor((Date.now() - auditStartTime) / 1000);
      setElapsedSeconds(elapsed);
      ...
    }, 1000);
    return () => clearInterval(interval);
```
- `FloatingTimerWidget.tsx:189-194` is the same. `gdpr` has an i18n key `seconds: '{{count}}s'` (i18n.ts:913).
- Either use antd `Statistic.Countdown` (available in 5.29; `value={pause_until*1000}`, `format="mm:ss"`, `onFinish` → invalidate the status query) or a small local interval.
- The spec's tag text "Paused (mm left)" needs minutes only, so a 30 s tick or a `refetchInterval` on the status query while paused is enough.

**dayjs.** Used with per-file `dayjs.extend(relativeTime)`. There is no `duration` plugin.

---

## 5. i18n

- The library is `i18next` with `react-i18next` and `i18next-browser-languagedetector`.
- Everything is in one file, `src/lib/i18n.ts` (2834 lines). `main.tsx:10` imports it for side effects.
- Both languages are inline objects in `const resources`:
  - EN is `resources.en.translation`, line 14-1418.
  - DE is `resources.de.translation`, line 1420-2813.
- There are no JSON files.
- Init is at the file end: `fallbackLng: 'en'`, `interpolation.escapeValue: false`, language persisted in localStorage under `lsm-language`.

**Key naming.** Keys are nested camelCase objects under top-level namespaces. EN line numbers:
- `common` 17, `nav` 76, `login` 99, `dashboard` 133, `projects` 247
- `todos` 345, `resources` 384, `vault` 414, `library` 540, `support` 594
- `team` 601, `tags` 681, `activity` 725, `settings` 750, `gdpr` 822
- `accessibility` 918, `time` 984, `approvals` 1069, `invoices` 1135, `analytics` 1211
- `reports` 1239, `availability` 1265, `theme` 1305, `language` 1312, `errors` 1318
- `integrationTokens` 1328

Other conventions:
- DE mirrors the same order from 1423 to 2721.
- Sub-groups are named `card`, `table`, `toasts`, `revokeConfirm`, `create`, `form`, `messages`.
- Interpolation is `{{name}}`. Counts use `{{count}}`. The legacy plural suffix `_plural` exists once (i18n.ts:1026-1027).

**Existing security keys.**
- The SecuritySection itself has none.
- The only `security` keys are the project security-status labels.
- EN, i18n.ts:321-326:
```ts
        security: {
          secure: 'Secure',
          monitoring: 'Monitoring',
          compromised: 'At Risk',
          hacked: 'Hacked',
        },
```
- DE, i18n.ts:1727-1732:
```ts
        security: {
          secure: 'Sicher',
          monitoring: 'Überwachung',
          compromised: 'Gefährdet',
          hacked: 'Gehackt',
        },
```
- Related labels:
  - `projects.filters.allSecurity`: 'All Security' (267) / 'Alle Sicherheit' (1673)
  - `projects.table.security`: 279 / 1685
  - `projects.form.securityStatus`: 303 / 1709
- `projects.security` is taken by those status labels. Do not nest hardening under it.
- The closest sibling precedent for section-scoped keys is `projects.uptime.*` (EN 314-320, DE 1720-1726).
- Use `projects.hardening.*`, inserted after `projects.uptime` in both languages. A new top-level `hardening` namespace after `integrationTokens` would also work.

**How components call `t()`.** From `UptimeSection.tsx:25,36,196`:
```tsx
import { useTranslation } from 'react-i18next';
  const { t } = useTranslation();
  {t('projects.uptime.checkedAutomatically')}
```
- With interpolation: `t('integrationTokens.revokeConfirm.title', { name: token.name })`.
- With a dynamic key: ``t(`integrationTokens.scopeTags.${SCOPE_META[scope].key}`)`` (IntegrationTokensCard.tsx:83). This pattern suits `projects.hardening.reasons.${reason}`.
- Outside React: `i18n.language` (`useAiChat.ts:75`).

**DE register.** Informal du-form and „…“ quotes. Example: `title: 'Token „{{name}}“ widerrufen?'`, `content: 'Jeder Client, der diesen Token verwendet, verliert sofort den Zugriff. Das lässt sich nicht rückgängig machen.'` (i18n.ts ~2742-2746).

---

## 6. Roles and permissions on the frontend

**Source of truth.** The zustand store `src/stores/auth.ts`, persisted as `lsm-auth`. `User` comes from `@lsm/types` (`packages/types/src/index.ts:16,38-43`):
```ts
export type UserRole = 'admin' | 'manager' | 'developer' | 'viewer';
export interface User { id: number; name: string; email: string; role: UserRole; is_admin: boolean; ...
```

**Helpers, `src/stores/auth.ts:80-107`:**
```ts
export function useCurrentUser() {
  return useAuthStore((state) => state.user);
}
export function useHasRole(roles: User['role'] | User['role'][]) {
  const user = useCurrentUser();
  const roleArray = Array.isArray(roles) ? roles : [roles];
  return user ? roleArray.includes(user.role) : false;
}
/** Hook to check if user is admin (by role or is_admin flag) */
export function useIsAdmin() {
  const user = useCurrentUser();
  return user ? (user.role === 'admin' || user.is_admin) : false;
}
export function useCanManageProjects() {
  const user = useCurrentUser();
  return user ? (user.role === 'admin' || user.role === 'manager' || user.is_admin) : false;
}
```

**"Manager who manages this project"** is computed only in `ProjectDetailPageV2.tsx:234-235`:
```tsx
  const canEdit = isAdmin || (currentUser?.role === 'manager' && (project.manager_id === currentUser?.id || project.managers?.some(m => m.id === currentUser?.id)));
  const canDelete = isAdmin; // Only admins can delete
```

**Server-provided abilities.** There is no precedent. A grep for `.can`, `can:`, `.abilities` and `.permissions` in `src` finds nothing.
- The spec's `can: {pause, enable, disable}` from the status response is new to this SPA.
- It is compatible, and better than re-deriving roles client-side.

**Role gating in the UI.** The codebase hides controls rather than disabling them with a tooltip.
- Examples: `{canEdit && (<Button …>Edit</Button>)}` (ProjectDetailPageV2.tsx:382-386) and `{canManage && (` (CredentialsSection.tsx:251, 301, 353).
- No example exists of a button that is role-disabled and has a tooltip.
- Closest analogue for a role-disabled control with an explanation, `CreateTokenModal.tsx:302-313`:
```tsx
                  const disabled = !scope.roles.includes(role);
                  return (
                    <Checkbox key={scope.value} value={scope.value} disabled={disabled}>
                      ...
                        {scope.hint}
                        {disabled && t('integrationTokens.create.scopeUnavailable')}
```
  Its string is `scopeUnavailable: ' — not available for your role'`, i18n.ts:1400.
- Closest analogue for a Tooltip around a disabled Button, `BackupsSection.tsx:259-266`:
```tsx
          <Tooltip title="Download">
            <Button type="text" icon={<CloudDownloadOutlined />} onClick={() => handleDownload(record)}
              disabled={record.status !== 'completed'} />
          </Tooltip>
```
- So the spec's "disabled with tooltip" is a new but workable pattern: `<Tooltip title={!can.disable ? t('projects.hardening.noPermission.disable') : undefined} color="#1e293b"><Switch disabled={!can.disable} …/></Tooltip>`.
- I did not test Tooltip hover over a disabled control on antd 5.29; check it once in the browser.

---

## 7. Tests and tooling

**There is no test runner in this repo.**
- `package.json` scripts are only `dev`, `build`, `preview`, `lint` and `typecheck`.
- `node_modules` has no vitest, jest, @testing-library, jsdom, happy-dom or msw.
- Apart from two spec docs matched by name, `git ls-files` shows no test, spec, mock, husky or CI files.
- There are therefore no component tests, hook tests or API mocks to quote.
- Prior plans state this explicitly. `docs/superpowers/plans/2026-07-22-mobile-responsive-phase-1-2.md:16`: "**No test harness in this repo.** The gate for every task is `npm run typecheck && npm run build && npm run lint`, all exit 0."
- `docs/superpowers/specs/2026-07-18-reactivity-overhaul-design.md:257-259` says the same.

**Commands, run from the repo root:**
- Typecheck: `npm run typecheck` (= `tsc --noEmit`).
- Lint everything: `npm run lint` (= `eslint . --max-warnings 0`).
- Lint one file: `npx eslint src/features/projects/components/HardeningCard.tsx --max-warnings 0`.
- Build: `npm run build` (= `vite build`). It does not run tsc, so always pair it with typecheck.
- Single test: not available.

**Local verification run on 2026-09-21.** Working tree is master plus the uncommitted work.
- `npx tsc --noEmit` exited 0 in about 8 s.
- `npm run lint` exited 0 in about 3 s.
- `git status` was unchanged afterwards.
- I did not run `npm run build`. It writes to `dist/`, which is gitignored but inside the repo, and this task is read-only.

**Lint rules that bite new code.** Only `js.configs.recommended`, `react-hooks/rules-of-hooks` and the query-key literal ban. `react-hooks/exhaustive-deps`, `no-unused-vars` and `no-undef` are off.

---

## 8. Git state

- The default branch is `master`. `origin/master`, `master` and `HEAD` are all `82c6ad4`.
- The current branch is `fix/concurrent-plugin-update-guard`. It has no commits beyond master, only uncommitted edits:
```
 M src/features/projects/components/WordPressManagement.tsx
 M src/features/projects/components/WordPressManagementDrawer.tsx
 M src/features/projects/components/WordPressManagementTab.tsx
 3 files changed, 27 insertions(+), 4 deletions(-)
```
- All three hunks are the same change: `updateAllPluginsMutation.onSuccess` now handles `data.locked` with `message.warning(...)` and `data.health_after >= 500` with `message.error(...)`.
- **There is no overlap with Part 3.** Part 3 touches:
  - `src/features/projects/components/sections/SecuritySection.tsx`
  - `src/lib/lsm-api.ts`
  - `src/lib/queryKeys.ts`
  - `src/lib/i18n.ts`
  - new `src/features/projects/components/HardeningCard.tsx`
  - new `src/features/projects/hooks/useHardening.ts`
- `git diff master --stat` is empty for every existing file on that list.
- No `feature/htaccess-hardening` branch exists yet, and there are no stashes.
- The feature branch must be created from `master` without disturbing this working tree. Use a separate git worktree, or wait until the owner commits.

---

## 9. Specs and plans location

- `docs/superpowers/specs/`:
  - `2026-07-18-reactivity-overhaul-design.md`
  - `2026-07-22-mobile-responsive-design.md`
- `docs/superpowers/plans/`:
  - `2026-07-16-advanced-ticketing-web.md`
  - `2026-07-18-reactivity-phase-0-1.md`
  - `2026-07-22-mobile-responsive-phase-1-2.md`
- The naming convention is `YYYY-MM-DD-<topic>-design.md` for specs and `YYYY-MM-DD-<topic>[-phase].md` for plans.
- The hardening spec itself lives in `lsm-api/docs/superpowers/specs/`. A web plan would go in `lsm-web/docs/superpowers/plans/2026-09-21-htaccess-hardening-web.md`.
- There is also a `.superpowers/` directory at the repo root, which I did not inspect.

---

## 10. Conflicts between Part 3 of the spec and this codebase

1. **No test harness.**
   - Spec line 216 asks for web component tests for status rendering and permission-disabled states. The repo has no runner (section 7).
   - Option (a): add vitest, @testing-library/react and jsdom plus a `test` script and config. This is new infrastructure and would be the first tests in the repo.
     - Tests would need a wrapper with `QueryClientProvider`, antd `<App>` and the i18n init.
     - They would use `vi.mock('@/lib/api')`, since nothing like msw exists.
   - Option (b): follow house practice. That means `npm run typecheck && npm run build && npm run lint` plus manual browser verification.
   - This needs a decision before planning.

2. **The parent section is not internationalised.**
   - Spec line 205 asks for i18n EN + DE. SecuritySection.tsx has zero `t()` calls, so an i18n'd HardeningCard will sit inside an English-only section.
   - That is acceptable. `ConnectWordPressCard` is in the same situation and imports `useTranslation`.
   - There is no existing `security` section namespace, and `projects.security` is already used for the status labels. Use `projects.hardening.*`.

3. **Timeout.**
   - The axios default is 30 s (`src/lib/api.ts:46`).
   - The API waits up to 120 s for the plugin (spec line 166).
   - All three POSTs (`rule`, `pause`, `resume`) must pass `{ timeout: … }` as `updatePlugin` does (lsm-api.ts:167).
   - Use more than 120000, for example 130000. Otherwise the browser aborts just before the API answers, and the UI reports failure for a change that may have been applied.
   - On any error, including a timeout, invalidate `queryKeys.projects.securityHardening(id)` so the card shows the real state.

4. **The 422 contract needs a `message` key.**
   - Rollbacks arrive as a rejected promise, so they are handled in `onError`, not `onSuccess`.
   - The interceptor (client.ts:75-77) and `getApiErrorMessage` only read `data.message` and `data.errors`.
   - The existing LsmController failure bodies use `{'error': '…'}` (lsm-api `LsmController.php:906,912`). Neither helper reads that key, so the toast would show "Request failed with status code 422".
   - Part 2 must return `{ success:false, message, reason, …status }`.
   - The web reads `error.response?.data?.reason` and maps it through ``t(`projects.hardening.reasons.${reason}`)`` with a fallback to `message`.
   - Reasons to translate:
     - `asset_broken`
     - `rule_ineffective`
     - `write_failed`
     - `loopback_blocked`
     - `pause_ineffective_foreign_rule`
     - `crash_recovered`
     - preflight and unsupported reasons
   - Also check `response.data.success === false` on 2xx in `onSuccess`. Spec line 159-161 warns about this, and the existing toggle at SecuritySection.tsx:132 does not do it.

5. **The response envelope is unknown.** Existing LSM endpoints pass the plugin JSON straight through (`{success, data:{…}}`), and callers unwrap with `r.data?.data || r.data`. Use the same unwrap in `useHardening` until the Part 2 shape is fixed. The expected fields are:
   - `rules`
   - `pause_until`
   - `server`
   - `last_result`
   - `pause_overdue`
   - `can`

6. **Disabled with tooltip and `can` from the API are both new patterns.**
   - Today the SPA hides controls by role and derives roles client-side (section 6).
   - Nothing blocks the spec's approach, but there is no example to copy.
   - Do not re-derive roles with `useIsAdmin`. The API's `can` already accounts for the `is_admin` flag and for which managers manage the project.
   - If `can` is missing, which can happen when the web is deployed before the API, treat every ability as false.

7. **Duration select inside the confirm.** `modal.confirm` content does not re-render with React state (section 4). Put the 15/30/60 Select, default 60, beside the button or use a controlled `<Modal>`.

8. **Countdown.** No component exists for it. Use `Statistic.Countdown` or a local interval, and refetch the status when it reaches zero.
   - `pause_until` is unix seconds (spec line 72), so multiply by 1000 for JS.
   - Show an `<Alert type="warning">` when `pause_overdue` is true (spec line 184-185).

9. **Switch versus confirm flow.**
   - An antd `Switch` flips only when its `checked` prop changes.
   - Keep `checked` driven by the server status and open the confirm from `onChange`.
   - Use `loading` per rule, mirroring `updatingSetting` (SecuritySection.tsx:88, 443).
   - `status === 'manual'` should render as not managed, with the enable action labelled "Adopt" or similar (spec line 139-141).
   - `unsupported` should disable the control with a reason tooltip.

10. **"Already large" is accurate.** The file is 879 lines.
    - Extraction is consistent with the ConnectWordPressCard precedent.
    - The one-line insertion at 615/617 plus one import is the only edit needed in SecuritySection.tsx.
    - The security score (SecuritySection.tsx:227-237, "7 controls") is not mentioned in the spec. Leave it untouched.

11. **Status query on the security tab.**
    - Opening the tab already fires health, security-settings and security-headers requests to the client site.
    - The hardening GET adds a fourth.
    - Use `enabled: hasLsmConnection` (passed in, or derived from `project.has_health_check_secret`) and `staleTime: 30000`.
    - The global `retry: 1` and `refetchOnWindowFocus: true` apply (`main.tsx:12-26`).
    - On sites running a plugin older than 2.10.0 the GET will fail, probably with 404 or 500. Render a quiet "requires plugin ≥ 2.10.0 / unavailable" state instead of an error toast.