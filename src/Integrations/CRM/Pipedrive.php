<?php
/**
 * Freeform for Craft
 *
 * @package       Solspace:Freeform
 * @author        Solspace, Inc.
 * @copyright     Copyright (c) 2008-2016, Solspace, Inc.
 * @link          https://solspace.com/craft/freeform
 * @license       https://solspace.com/software/license-agreement
 */

namespace Solspace\FreeformPro\Integrations\CRM;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;
use Solspace\Freeform\Library\Exceptions\Integrations\IntegrationException;
use Solspace\Freeform\Library\Integrations\CRM\AbstractCRMIntegration;
use Solspace\Freeform\Library\Integrations\DataObjects\FieldObject;
use Solspace\Freeform\Library\Integrations\IntegrationStorageInterface;
use Solspace\Freeform\Library\Integrations\SettingBlueprint;
use Solspace\Freeform\Library\Logging\LoggerInterface;

class Pipedrive extends AbstractCRMIntegration
{
    const SETTING_API_TOKEN = 'api_token';
    const TITLE             = 'Pipedrive';
    const LOG_CATEGORY      = 'Pipedrive';

    /**
     * Returns a list of additional settings for this integration
     * Could be used for anything, like - AccessTokens
     *
     * @return SettingBlueprint[]
     */
    public static function getSettingBlueprints(): array
    {
        return [
            new SettingBlueprint(
                SettingBlueprint::TYPE_TEXT,
                self::SETTING_API_TOKEN,
                'API Token',
                'Enter your Pipedrive API token here.',
                true
            ),
        ];
    }

    /**
     * Push objects to the CRM
     *
     * @param array $keyValueList
     *
     * @return bool
     */
    public function pushObject(array $keyValueList): bool
    {
        $client = new Client();

        $organizationFields = $personFields = $dealFields = $notesFields = [];
        foreach ($keyValueList as $key => $value) {
            $matches = null;
            if (preg_match('/^org___(.*)$/', $key, $matches)) {
                $organizationFields[$matches[1]] = $value;
            } else if (preg_match('/^prsn___(.*)$/', $key, $matches)) {
                $personFields[$matches[1]] = $value;
            } else if (preg_match('/^deal___(.*)$/', $key, $matches)) {
                $dealFields[$matches[1]] = $value;
            } else if (preg_match('/^note___(deal|org|prsn)$/', $key, $matches)) {
                $notesFields[$matches[1]] = $value;
            }
        }

        $organizationId = null;
        if ($organizationFields) {
            try {
                $response = $client->post(
                    $this->getEndpoint('/v1/organizations'),
                    [
                        'query' => ['api_token' => $this->getAccessToken()],
                        'json'  => $organizationFields,
                    ]
                );

                $json = \GuzzleHttp\json_decode((string) $response->getBody());
                if (isset($json->data->id)) {
                    $organizationId = $json->data->id;
                }
            } catch (RequestException $e) {
                $responseBody = (string) $e->getResponse()->getBody();

                $this->getLogger()->log(LoggerInterface::LEVEL_ERROR, $responseBody, self::LOG_CATEGORY);
                $this->getLogger()->log(LoggerInterface::LEVEL_ERROR, $e->getMessage(), self::LOG_CATEGORY);
            } catch (\Exception $e) {
                $this->getLogger()->log(LoggerInterface::LEVEL_WARNING, $e->getMessage(), self::LOG_CATEGORY);
            }
        }

        $personId = null;
        if ($personFields) {
            try {
                if ($organizationId) {
                    $personFields['org_id'] = $organizationId;
                }

                $response = $client->post(
                    $this->getEndpoint('/v1/persons'),
                    [
                        'query' => ['api_token' => $this->getAccessToken()],
                        'json'  => $personFields,
                    ]
                );

                $json = \GuzzleHttp\json_decode((string) $response->getBody());
                if (isset($json->data->id)) {
                    $personId = $json->data->id;
                }
            } catch (RequestException $e) {
                $responseBody = (string) $e->getResponse()->getBody();

                $this->getLogger()->log(LoggerInterface::LEVEL_ERROR, $responseBody, self::LOG_CATEGORY);
                $this->getLogger()->log(LoggerInterface::LEVEL_ERROR, $e->getMessage(), self::LOG_CATEGORY);
            } catch (\Exception $e) {
                $this->getLogger()->log(LoggerInterface::LEVEL_WARNING, $e->getMessage(), self::LOG_CATEGORY);
            }
        }

        $dealId = null;
        try {
            if ($personId) {
                $dealFields['person_id'] = $personId;
            }

            if ($organizationId) {
                $dealFields['org_id'] = $organizationId;
            }

            $response = $client->post(
                $this->getEndpoint('/v1/deals'),
                [
                    'query' => ['api_token' => $this->getAccessToken()],
                    'json'  => $dealFields,
                ]
            );

            $json   = \GuzzleHttp\json_decode((string) $response->getBody(), false);
            $dealId = $json->data->id;
        } catch (RequestException $e) {
            $responseBody = (string) $e->getResponse()->getBody();

            $this->getLogger()->log(LoggerInterface::LEVEL_ERROR, $responseBody, self::LOG_CATEGORY);
            $this->getLogger()->log(LoggerInterface::LEVEL_ERROR, $e->getMessage(), self::LOG_CATEGORY);
        } catch (\Exception $e) {
            $this->getLogger()->log(LoggerInterface::LEVEL_WARNING, $e->getMessage(), self::LOG_CATEGORY);
        }

        try {
            if ($dealId && !empty($notesFields['deal'])) {
                $client->post(
                    $this->getEndpoint('/v1/notes'),
                    [
                        'query' => ['api_token' => $this->getAccessToken()],
                        'json'  => [
                            'content'             => $notesFields['deal'],
                            'deal_id'             => $dealId,
                            'pinned_to_deal_flag' => '1',
                        ],
                    ]
                );
            }

            if ($organizationId && !empty($notesFields['org'])) {
                $client->post(
                    $this->getEndpoint('/v1/notes'),
                    [
                        'query' => ['api_token' => $this->getAccessToken()],
                        'json'  => [
                            'content'                     => $notesFields['org'],
                            'org_id'                      => $organizationId,
                            'pinned_to_organization_flag' => '1',
                        ],
                    ]
                );
            }

            if ($personId && !empty($notesFields['prsn'])) {
                $client->post(
                    $this->getEndpoint('/v1/notes'),
                    [
                        'query' => ['api_token' => $this->getAccessToken()],
                        'json'  => [
                            'content'               => $notesFields['prsn'],
                            'person_id'             => $personId,
                            'pinned_to_person_flag' => '1',
                        ],
                    ]
                );
            }
        } catch (RequestException $e) {
            $responseBody = (string) $e->getResponse()->getBody();

            $this->getLogger()->log(LoggerInterface::LEVEL_ERROR, $responseBody, self::LOG_CATEGORY);
            $this->getLogger()->log(LoggerInterface::LEVEL_ERROR, $e->getMessage(), self::LOG_CATEGORY);
        } catch (\Exception $e) {
            $this->getLogger()->log(LoggerInterface::LEVEL_WARNING, $e->getMessage(), self::LOG_CATEGORY);
        }

        return (bool) $dealId;
    }

    /**
     * Check if it's possible to connect to the API
     *
     * @return bool
     */
    public function checkConnection(): bool
    {
        $client   = new Client();
        $endpoint = $this->getEndpoint('/v1/deals');

        try {
            $response = $client->get(
                $endpoint,
                [
                    'query'   => ['api_token' => $this->getAccessToken(), 'limit' => 1],
                    'headers' => ['Accept' => 'application/json'],
                ]
            );

            $json = json_decode((string) $response->getBody(), false);

            return isset($json->success) && $json->success === true;
        } catch (RequestException $exception) {
            throw new IntegrationException($exception->getMessage(), $exception->getCode(), $exception->getPrevious());
        }
    }

    /**
     * Fetch the custom fields from the integration
     *
     * @return FieldObject[]
     */
    public function fetchFields(): array
    {
        $endpoints = [
            'prsn' => 'personFields',
            'org'  => 'organizationFields',
            'deal' => 'dealFields',
        ];

        $allowedFields = [
            'name',
            'phone',
            'email',
            'title',
            'value',
            'currency',
            'stage_id',
            'status',
            'probability',
        ];

        $requredFields = [
            'name',
            'title',
        ];

        $fieldList = [];
        foreach ($endpoints as $category => $endpoint) {
            $response = $this->getResponse(
                $this->getEndpoint('/v1/' . $endpoint),
                ['query' => ['limit' => 999]]
            );

            $json = \GuzzleHttp\json_decode($response->getBody(), false);

            if (!isset($json->success) || !$json->success) {
                throw new IntegrationException("Could not fetch fields for {$category}");
            }

            foreach ($json->data as $fieldInfo) {
                switch ($fieldInfo->field_type) {
                    case 'varchar':
                    case 'varchar_auto':
                    case 'text':
                    case 'date':
                    case 'enum':
                    case 'time':
                    case 'timerange':
                    case 'daterange':
                        $type = FieldObject::TYPE_STRING;
                        break;

                    case 'set':
                    case 'phone':
                        $type = FieldObject::TYPE_ARRAY;
                        break;

                    case 'int':
                    case 'double':
                    case 'monetary':
                    case 'user':
                    case 'org':
                    case 'people':
                        $type = FieldObject::TYPE_NUMERIC;
                        break;

                    default:
                        continue 2;
                }

                if (
                    preg_match('/[a-z0-9]{40}/i', $fieldInfo->key)
                    || \in_array($fieldInfo->key, $allowedFields, true)
                ) {
                    $fieldList[] = new FieldObject(
                        "{$category}___{$fieldInfo->key}",
                        "($category) {$fieldInfo->name}",
                        $type,
                        \in_array($fieldInfo->key, $requredFields, true)
                    );
                }
            }

            $fieldList[] = new FieldObject(
                "note___{$category}",
                "({$category}) Note",
                FieldObject::TYPE_STRING,
                false
            );
        }

        return $fieldList;
    }

    /**
     * Authorizes the application
     * Returns the access_token
     *
     * @return string
     * @throws IntegrationException
     */
    public function fetchAccessToken(): string
    {
        return $this->getSetting(self::SETTING_API_TOKEN);
    }

    /**
     * A method that initiates the authentication
     */
    public function initiateAuthentication()
    {
    }

    /**
     * Perform anything necessary before this integration is saved
     *
     * @param IntegrationStorageInterface $model
     */
    public function onBeforeSave(IntegrationStorageInterface $model)
    {
        $model->updateAccessToken($this->getSetting(self::SETTING_API_TOKEN));
    }

    /**
     * @param string $endpoint
     * @param array  $queryOptions
     *
     * @return ResponseInterface
     */
    private function getResponse(string $endpoint, array $queryOptions = []): ResponseInterface
    {
        $client = new Client();

        return $client->get(
            $endpoint,
            [
                'query'   => array_merge(
                    ['api_token' => $this->getAccessToken()],
                    $queryOptions ?? []
                ),
                'headers' => ['Accept' => 'application/json'],
            ]
        );
    }

    /**
     * @return string
     */
    protected function getApiRootUrl(): string
    {
        return 'https://api.pipedrive.com/';
    }
}
