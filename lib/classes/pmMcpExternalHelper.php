<?php

/**
 * External links between a pm task and a record in another app.
 *
 * pm does not have a helpdesk-specific link table. Every "this task is about
 * that helpdesk request / crm deal / shop order" relation is one row in the
 * generic pm_task_external (task_id, app_id, external_id, create_datetime;
 * PRIMARY KEY over all three id columns) — see wa-apps/pm/lib/config/db.php.
 * The three apps share one shape, so this helper is the one place that knows
 * their differences: which table to check existence against and how to name
 * the linked record.
 *
 * No app-context switch. pmMcpToolBase::safeExecute() sets wa()->setUser()
 * programmatically (there is no session to fall back to), and that does not
 * survive a system re-instantiation — not just wa('pm', 1), any wa($app, 1).
 * pm's own enrichment (pmTask.actions.php::_doEnrich) gets away with
 * wa('shop', 1) for shopHelper::encodeOrderId() because its caller has a real
 * backend session; an MCP tool call does not. So every lookup here goes
 * through a plain SQL query against the other app's table instead of booting
 * that app — the same trick pm's own _doEnrich already uses for helpdesk
 * ("SELECT summary FROM helpdesk_request WHERE id = i:0") and crm, extended to
 * shop rather than switching for it.
 */
class pmMcpExternalHelper
{
    const APP_HELPDESK = 'helpdesk';
    const APP_CRM      = 'crm';
    const APP_SHOP     = 'shop';

    /**
     * Every app pm's external-link table knows how to point at. Source of the
     * app_id enum on both tools.
     *
     * @return string[]
     */
    public static function apps()
    {
        return array(self::APP_HELPDESK, self::APP_CRM, self::APP_SHOP);
    }

    /**
     * The table each app's linked record lives in, keyed by app_id. Used by
     * externalExists() for all three apps and by elementName() for helpdesk
     * and crm (shop is looked up by id only — see elementName()).
     *
     * helpdesk_request and crm_deal are confirmed against pm's own SQL in
     * pmTask.actions.php::_doEnrich. shop_order is the standard Shop-Script
     * table name but is not confirmed against a live install — shop is not
     * installed in this environment. Verify against
     * wa-apps/shop/lib/config/db.php before relying on it against a real shop
     * install.
     *
     * @return array app_id => table name
     */
    protected static function tables()
    {
        return array(
            self::APP_HELPDESK => 'helpdesk_request',
            self::APP_CRM      => 'crm_deal',
            self::APP_SHOP     => 'shop_order',
        );
    }

    /**
     * The column holding the human-readable name/summary, for the apps
     * elementName() reads by raw SQL (helpdesk, crm — see its docblock).
     *
     * @return array app_id => column name
     */
    protected static function nameColumns()
    {
        return array(
            self::APP_HELPDESK => 'summary',
            self::APP_CRM      => 'name',
        );
    }

    /**
     * Canonicalise an external id to the form every tool writes and reads.
     *
     * external_id is a varchar(255) and part of pm_task_external's PRIMARY KEY,
     * so "123" and "0123" are two distinct rows pointing at the same helpdesk
     * request. All three integrated apps address their records by integer id,
     * so int-casting and casting back closes that off.
     *
     * @param mixed $raw
     * @return string  Normalised id, or '' when nothing numeric was there.
     */
    public static function normalizeExternalId($raw)
    {
        if (!is_scalar($raw)) {
            return '';
        }
        $trimmed = trim((string) $raw);
        if (!preg_match('/^\d+$/', $trimmed)) {
            return '';
        }
        $id = (int) $trimmed;
        return $id > 0 ? (string) $id : '';
    }

    /**
     * Refuse to link against an app that is not installed. Checked before any
     * query against that app's tables — the guard against a query on a table
     * that plain does not exist on this install, not just a courtesy message.
     *
     * @throws waAPIException not_found when the app is not installed.
     */
    public static function assertLinkable($app_id)
    {
        if (!in_array($app_id, self::apps(), true)) {
            throw new waAPIException(
                'invalid_param',
                sprintf(_wp('Unknown app_id "%s". Use one of: %s.'), $app_id, implode(', ', self::apps())),
                400
            );
        }
        if (!wa()->appExists($app_id)) {
            throw new waAPIException(
                'not_found',
                sprintf(_wp('The %s app is not installed; a link to it cannot be created.'), $app_id),
                404
            );
        }
    }

    /**
     * Whether a record exists in the linked app, by raw SQL against its table
     * — no app switch, see the class docblock. pm's own controllers never make
     * this check (externalAddAction links even a nonexistent request/deal/order
     * without complaint); this tool does, so a typo in the id fails at write
     * time rather than silently producing a link nothing will ever resolve.
     *
     * @param string $app_id       One of apps().
     * @param string $external_id  Normalised id (normalizeExternalId()).
     * @return bool
     */
    public static function externalExists($app_id, $external_id)
    {
        $tables = self::tables();
        if (!isset($tables[$app_id]) || $external_id === '') {
            return false;
        }
        $table = $tables[$app_id];
        $model = new pmTaskModel();
        try {
            $found = $model->query(
                sprintf('SELECT id FROM %s WHERE id = i:id', $table),
                array('id' => (int) $external_id)
            )->fetchField();
            return $found !== false && $found !== null;
        } catch (Exception $e) {
            // The other app's table is missing or unreadable (e.g. an install
            // where the app was removed after links were written). Treat as
            // "cannot confirm" rather than fatal; callers report not_found.
            return false;
        }
    }

    /**
     * Human-readable name for a linked record, mirroring pm's own
     * pmTask.actions.php::_doEnrich for helpdesk and crm. shop does not use
     * shopHelper::encodeOrderId() (that would need an app switch, see the class
     * docblock) — it falls back to the bare id like every app whose row could
     * not be read.
     *
     * @return string|null  null when the record cannot be found or read.
     */
    protected static function elementName($app_id, $external_id)
    {
        if ($external_id === '') {
            return null;
        }
        if ($app_id === self::APP_SHOP) {
            // No shopHelper::encodeOrderId() here — see class docblock. The
            // bare id is still a valid, clickable identifier via url below.
            return '#' . $external_id;
        }
        $tables = self::tables();
        $columns = self::nameColumns();
        if (!isset($tables[$app_id], $columns[$app_id])) {
            return null;
        }
        $table = $tables[$app_id];
        $column = $columns[$app_id];
        $model = new pmTaskModel();
        try {
            $name = $model->query(
                sprintf('SELECT %s FROM %s WHERE id = i:id', $column, $table),
                array('id' => (int) $external_id)
            )->fetchField();
            return $name !== false && $name !== null ? (string) $name : null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * The backend URL for the linked record, mirroring
     * pmTask.actions.php::_doEnrich's url construction.
     */
    protected static function elementUrl($app_id, $external_id)
    {
        if (!wa()->appExists($app_id)) {
            return null;
        }
        switch ($app_id) {
            case self::APP_HELPDESK:
                return wa()->getAppUrl('helpdesk') . '#/request/' . $external_id . '/';
            case self::APP_CRM:
                return wa()->getAppUrl('crm') . '#/deal/' . $external_id . '/';
            case self::APP_SHOP:
                return wa()->getAppUrl('shop') . '?action=orders#/order/' . $external_id . '/';
            default:
                return wa()->getAppUrl($app_id);
        }
    }

    /**
     * Describe one link for a tool response: enough for an agent to tell a
     * live link from a dangling one without a second call.
     *
     * Unlike pm's own pmTask.actions.php::_doEnrich, which silently drops a
     * link whose app is not installed (`continue`, line ~1450) and never
     * reconciles a deleted linked record (pm has no reverse cascade — see
     * pmTaskExternal.model.php), this always returns the row. app_installed
     * and exists say why element_name is null instead of the row vanishing,
     * so a dangling link stays visible and removable through
     * pm_manage_external_links.
     *
     * @param string $app_id
     * @param string $external_id  As stored (not necessarily normalised —
     *                              legacy rows written before this plugin may
     *                              carry an un-normalised id).
     * @return array
     */
    public static function describeExternal($app_id, $external_id)
    {
        $installed = wa()->appExists($app_id);
        $normalized = self::normalizeExternalId($external_id);
        $lookup_id = $normalized !== '' ? $normalized : (string) $external_id;

        $exists = $installed && in_array($app_id, self::apps(), true)
            ? self::externalExists($app_id, $lookup_id)
            : false;

        $app_info = $installed ? wa()->getAppInfo($app_id) : null;

        return array(
            'app_id'              => $app_id,
            'app_name'            => $app_info['name'] ?? $app_id,
            'external_id'         => (string) $external_id,
            'app_installed'       => $installed,
            'exists'              => $exists,
            'element_name'        => $exists ? self::elementName($app_id, $lookup_id) : null,
            'url'                 => $installed ? self::elementUrl($app_id, $lookup_id) : null,
            // Purely informational: pmHelper::isIntegrationEnabled() gates only
            // pm's UI injection into the other app's pages (see
            // wa-apps/pm/lib/handlers/helpdesk.*.handler.php), not whether a
            // link may be created or read — externalAdd/externalRemove and the
            // API v1 methods ignore it entirely, and pm's own task view renders
            // existing links regardless of the flag. It is also unreliable:
            // pmHelper::getConfig() returns the first config file it finds
            // without merging, so an install with a saved
            // wa-config/apps/pm/config.php lacking an "integrations" key reads
            // as disabled even though the feature works. Never gate on this.
            'integration_enabled' => $installed && in_array($app_id, self::apps(), true)
                ? pmHelper::isIntegrationEnabled($app_id)
                : false,
        );
    }

    /**
     * A task's external links, enriched. Used by pmMcpTaskHelper::formatTaskCard().
     *
     * @param int $task_id
     * @return array[]
     */
    public static function forTask($task_id)
    {
        $links = (new pmTaskExternalModel())->getByTask((int) $task_id);
        $out = array();
        foreach ($links as $link) {
            $out[] = self::describeExternal($link['app_id'], $link['external_id']);
        }
        return $out;
    }
}
