<?php

/**
 * Abstract base for all pm (Project Management) MCP tools.
 *
 * Centralises the response envelope, schema validation, the app-context
 * switch and error plumbing. Modelled on helpdeskMcpToolBase.
 *
 * Every concrete tool wraps its body in safeExecute(), which:
 *   - rejects anonymous callers (token act_as = 0) with access_denied,
 *   - switches the active app to pm while preserving the authenticated user,
 *   - maps thrown exceptions onto the ok/softFail response envelope.
 */
abstract class pmMcpToolBase extends mcpTool
{
    /** @var mcpSchemaValidator|null */
    protected static $validator = null;

    public function validate(array $arguments)
    {
        if (self::$validator === null) {
            self::$validator = new mcpSchemaValidator();
        }

        $schema = $this->getInputSchema();

        // Task-reference arguments are declared as strings so that both "32"
        // and "AUTH-32" validate (see taskRefSchema()). A client that sends the
        // id as a JSON number is still right, so coerce numbers to strings for
        // every string-typed property rather than failing on the type alone.
        $properties = isset($schema['properties']) && is_array($schema['properties']) ? $schema['properties'] : array();
        foreach ($properties as $name => $property) {
            if (!is_array($property) || ($property['type'] ?? null) !== 'string') {
                continue;
            }
            if (array_key_exists($name, $arguments) && (is_int($arguments[$name]) || is_float($arguments[$name]))) {
                $arguments[$name] = (string) $arguments[$name];
            }
        }

        return self::$validator->validate($schema, $arguments);
    }

    /**
     * Schema fragment for an argument naming a task.
     *
     * pm shows a task as its project's number ("AUTH-32"), so that is what a
     * user quotes and what an LLM passes on. Declaring the argument as a string
     * lets both the bare id and the full number through one schema; validate()
     * keeps integer clients working and pmMcpTaskHelper::parseTaskRef() sorts
     * the shapes out.
     *
     * @param string $description  Role of this particular argument.
     * @return array
     */
    protected static function taskRefSchema($description)
    {
        return array(
            'type'        => 'string',
            'minLength'   => 1,
            'description' => $description
                . ' Accepts the numeric id ("32") or the full task number with the project prefix ("AUTH-32", "AUTH32", "32-AUTH");'
                . ' case, spaces and punctuation are ignored.',
        );
    }

    /**
     * Read an entity reference argument (a task id or full number) as the raw
     * scalar the client sent, trimmed. Unlike argInt(), it does not flatten
     * "AUTH-32" to 0.
     */
    protected function argRef(array $args, $key)
    {
        if (!isset($args[$key]) || !is_scalar($args[$key])) {
            return '';
        }
        return trim((string) $args[$key]);
    }

    /**
     * Successful response envelope.
     */
    protected function ok(array $payload)
    {
        return array_merge(array('ok' => true), $payload);
    }

    /**
     * Failure response envelope. Not an exception: the tool returns a
     * structured error the LLM can read and react to.
     */
    protected function softFail($code, $message, array $extra = array())
    {
        return array_merge(
            array(
                'ok'            => false,
                'error_code'    => $code,
                'error_message' => $message,
            ),
            $extra
        );
    }

    /**
     * Wrap an execute() body with the pm app-context switch and a catch-all
     * exception mapper.
     *
     * @param \Closure $closure  The tool body. Runs with pm as active app and
     *                           the authenticated user restored.
     * @return array
     */
    protected function safeExecute(\Closure $closure)
    {
        $previous_app = wa()->getApp();

        // Preserve the authenticated user across the app switch. The MCP
        // server sets waUser on the 'mcp' system via the token's act_as, but
        // wa('pm', 1) creates a fresh pm system instance that does not inherit
        // the user. Without restoring it, every project-membership and
        // permission check would run as an anonymous visitor.
        $previous_user = wa()->getUser();

        // Reject anonymous callers up front. All pm tools operate in the
        // context of a specific user (project roles, assignments, wiki
        // visibility); there is nothing meaningful to do without one.
        if (!$previous_user || !$previous_user->getId()) {
            return $this->softFail(
                'access_denied',
                _wp('This tool requires an authenticated user. The MCP token has no associated user (act_as = 0).')
            );
        }

        wa('pm', 1);
        wa()->setUser($previous_user);
        wa()->pushActivePlugin('pm', 'mcp');

        try {
            return $closure();
        } catch (waRightsException $e) {
            return $this->softFail('access_denied', $e->getMessage());
        } catch (waAPIException $e) {
            // pm's REST API throws waAPIException with "code: message" bodies.
            $raw = $e->getMessage();
            if (preg_match('/^([a-z_]+):\s*(.*)$/s', $raw, $m)) {
                $parsed_code = $m[1];
                $parsed_message = $m[2];
            } else {
                $parsed_code = 'api_error';
                $parsed_message = $raw;
            }
            return $this->softFail(
                $parsed_code,
                $parsed_message !== '' ? $parsed_message : $raw,
                array('api_code' => $e->getCode())
            );
        } catch (waDbException $e) {
            waLog::log('mcp_pm db error: ' . $e->getMessage(), 'mcp_pm.log');
            return $this->softFail('db_error', _wp('Database error. The action was not completed.'));
        } catch (waException $e) {
            return $this->softFail('app_error', $e->getMessage());
        } catch (\Throwable $e) {
            waLog::log('mcp_pm unexpected error: ' . get_class($e) . ': ' . $e->getMessage(), 'mcp_pm.log');
            return $this->softFail(
                'internal_error',
                _wp('Unexpected error. The action was not completed.')
            );
        } finally {
            wa()->popActivePlugin();
            if ($previous_app !== 'pm') {
                wa($previous_app, 1);
            }
        }
    }

    /**
     * Enforce confirm=true on destructive tools (delete_task, remove_project_user, ...).
     *
     * @param array      $args
     * @param array|null $error_out  Set to a softFail envelope when the guard fails.
     * @return bool  True when confirmed and it is safe to proceed.
     */
    protected function assertConfirm(array $args, ?array &$error_out)
    {
        if (empty($args['confirm']) || $args['confirm'] !== true) {
            $error_out = $this->softFail(
                'confirm_required',
                _wp('Destructive action requires explicit "confirm: true" in the arguments.'),
                array('hint' => 'Set confirm=true to proceed.')
            );
            return false;
        }
        return true;
    }

    /**
     * Read a boolean argument, tolerating the JSON true/false a client sends
     * as well as the "1"/"0"/"true"/"false" strings some transports produce.
     */
    protected function argBool(array $args, $key, $default = false)
    {
        if (!array_key_exists($key, $args) || $args[$key] === null || $args[$key] === '') {
            return (bool) $default;
        }
        if (is_bool($args[$key])) {
            return $args[$key];
        }
        return filter_var($args[$key], FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Current authenticated user's contact id. Always positive inside a tool
     * body because safeExecute() has already rejected anonymous callers.
     */
    protected function getUserId()
    {
        return (int) wa()->getUser()->getId();
    }

    /**
     * Flatten waModel::validate() / pmTask validation output into a single
     * human-readable string for a softFail message.
     */
    public static function flatValidation(array $messages)
    {
        $flat = array();
        foreach ($messages as $entry) {
            if (is_string($entry)) {
                if ($entry !== '') {
                    $flat[] = $entry;
                }
                continue;
            }
            if (!is_array($entry)) {
                continue;
            }
            foreach ($entry as $field => $message) {
                if (is_array($message)) {
                    foreach ($message as $sub) {
                        if ($sub !== '' && $sub !== null) {
                            $flat[] = is_string($field) ? "{$field}: {$sub}" : (string) $sub;
                        }
                    }
                } elseif ($message !== '' && $message !== null) {
                    $flat[] = is_string($field) ? "{$field}: {$message}" : (string) $message;
                }
            }
        }
        return implode('; ', $flat);
    }
}
