<?php

namespace Minds\Core\Analytics\Graphs\Aggregates;

use DateTime;
use Minds\Core\Data\cache\abstractCacher;
use Minds\Core\Data\ElasticSearch;
use Minds\Core\Data\ElasticSearch\Client;
use Minds\Core\Di\Di;
use Minds\Core\Analytics\Graphs\Manager;

class OnchainBoosts implements AggregateInterface
{
    /** @var Client */
    protected $client;

    /** @var abstractCacher */
    protected $cacher;

    /** @var string */
    protected $index;

    /** @var string */
    protected $dateFormat;

    public function __construct($client = null, $cacher = null)
    {
        $this->client = $client ?: Di::_()->get('Database\ElasticSearch');
        $this->cacher = $cacher ?: Di::_()->get('Cache\Redis');
        $this->index = 'minds-transactions-onchain*';
    }

    public function hasTTL(array $opts = [])
    {
        return false;
    }

    public function buildCacheKey(array $options = [])
    {
        return "onchain:boosts:{$options['key']}:{$options['unit']}";
    }

    /**
     * Fetch all
     * @param array $opts
     * @return array
     */
    public function fetchAll($opts = [])
    {
        $result = [];
        foreach ([
            'average',
            'average_reclaimed_tokens',
            'average_users',
            null,
        ] as $key) {
            foreach ([ 'day', 'month' ] as $unit) {
                $k = Manager::buildKey([
                    'aggregate' => $opts['aggregate'] ?? 'onchainboosts',
                    'key' => $key,
                    'unit' => $unit,
                ]);
                $result[$k] = $this->fetch([
                    'key' => $key,
                    'unit' => $unit,
                ]);
            }
        }
        return $result;
    }

    public function fetch(array $options = [])
    {
        $options = array_merge([
            'span' => 12,
            'unit' => 'day', // day / month
            'ignoreCache' => false,
            'userGuid' => null,
            'key' => null,
        ], $options);

        $key = $options['key'];

        $from = null;
        switch ($options['unit']) {
            case "day":
                $from = (new DateTime('midnight'))->modify("-{$options['span']} days");
                $to = (new DateTime('midnight'));
                $interval = '1d';
                $this->dateFormat = 'y-m-d';
                break;
            case "month":
                $from = (new DateTime('midnight first day of next month'))->modify("-{$options['span']} months");
                $to = new DateTime('midnight first day of next month');
                $interval = '1M';
                $this->dateFormat = 'y-m';
                break;
            default:
                throw new \Exception("{$options['unit']} is not an accepted unit");
        }

        switch ($key) {
            case 'average':
                return $this->getAvg($from, $to, $interval);
                break;
            case 'average_reclaimed_tokens':
                return $this->getAvgReclaimedTokens($from, $to, $interval);
                break;
            case 'average_users':
                return $this->getAvgUsers($from, $to, $interval);
                break;
            default: // no avg, show graph
                return $this->getGraph($from, $to, $interval);
                break;
        }
    }

    private function getAvg($from, $to, $interval)
    {
        $must = [
            [
                "match_all" => (object) []
            ],
            [
                "range" => [
                    "@timestamp" => [
                        "gte" => $from->getTimestamp() * 1000,
                        "lte" => $to->getTimestamp() * 1000,
                        "format" => "epoch_millis"
                    ]
                ]
            ],
            [
                "match_phrase" => [
                    "transactionCategory" => [
                        "query" => "boost"
                    ]
                ]
            ],
            [
                "match_phrase" => [
                    "function" => [
                        "query" => "approveAndCall"
                    ]
                ]
            ],
            [
                "match_phrase" => [
                    "isTokenTransaction" => [
                        "query" => true
                    ]
                ]
            ]
        ];

        $query = [
            'index' => $this->index,
            'size' => 0,
            "stored_fields" => [
                "*"
            ],
            "docvalue_fields" => [
                (object) [
                    "field" => "@timestamp",
                    "format" => "date_time"
                ]
            ],
            'body' => [
                'query' => [
                    'bool' => [
                        'must' => $must
                    ]
                ],
                "aggs" => [
                    "avg" => [
                        "avg_bucket" => [
                            "buckets_path" => "1-bucket>_count"
                        ]
                    ],
                    "1-bucket" => [
                        "date_histogram" => [
                            "field" => "@timestamp",
                            "interval" => $interval,
                            "min_doc_count" => 1
                        ]
                    ]
                ],
            ]
        ];

        $prepared = new ElasticSearch\Prepared\Search();
        $prepared->query($query);

        $result = $this->client->request($prepared);

        return $result['aggregations']['avg']['value'] ?? 0;
    }

    private function getAvgReclaimedTokens($from, $to, $interval)
    {
        $must = [
            [
                "match_all" => (object) []
            ],
            [
                "range" => [
                    "@timestamp" => [
                        "gte" => $from->getTimestamp() * 1000,
                        "lte" => $to->getTimestamp() * 1000,
                        "format" => "epoch_millis"
                    ]
                ]
            ],
            [
                "match_phrase" => [
                    "transactionCategory" => [
                        "query" => "boost"
                    ]
                ]
            ],
            [
                "match_phrase" => [
                    "function" => [
                        "query" => "approveAndCall"
                    ]
                ]
            ],
            [
                "match_phrase" => [
                    "isTokenTransaction" => [
                        "query" => true
                    ]
                ]
            ]
        ];

        $query = [
            'index' => $this->index,
            'size' => 0,
            "stored_fields" => [
                "*"
            ],
            "docvalue_fields" => [
                (object) [
                    "field" => "@timestamp",
                    "format" => "date_time"
                ]
            ],
            'body' => [
                'query' => [
                    'bool' => [
                        'must' => $must
                    ]
                ],
                "aggs" => [
                    "avg" => [
                        "avg_bucket" => [
                            "buckets_path" => "1-bucket>1-metric"
                        ]
                    ],
                    "1-bucket" => [
                        "date_histogram" => [
                            "field" => "@timestamp",
                            "interval" => $interval,
                            "min_doc_count" => 1
                        ],
                        "aggs" => [
                            "1-metric" => [
                                "sum" => [
                                    "field" => "tokenValue"
                                ]
                            ]
                        ]
                    ]
                ],
            ]
        ];

        $prepared = new ElasticSearch\Prepared\Search();
        $prepared->query($query);

        $result = $this->client->request($prepared);

        return $result['aggregations']['avg']['value'] ?? 0;
    }

    private function getAvgUsers($from, $to, $interval)
    {
        $must = [
            [
                "match_all" => (object) []
            ],
            [
                "range" => [
                    "@timestamp" => [
                        "gte" => $from->getTimestamp() * 1000,
                        "lte" => $to->getTimestamp() * 1000,
                        "format" => "epoch_millis"
                    ]
                ]
            ],
            [
                "match_phrase" => [
                    "transactionCategory" => [
                        "query" => "boost"
                    ]
                ]
            ],
            [
                "match_phrase" => [
                    "function" => [
                        "query" => "approveAndCall"
                    ]
                ]
            ],
            [
                "match_phrase" => [
                    "isTokenTransaction" => [
                        "query" => true
                    ]
                ]
            ]
        ];

        $query = [
            'index' => $this->index,
            'size' => 0,
            "stored_fields" => [
                "*"
            ],
            "docvalue_fields" => [
                (object) [
                    "field" => "@timestamp",
                    "format" => "date_time"
                ]
            ],
            'body' => [
                'query' => [
                    'bool' => [
                        'must' => $must
                    ]
                ],
                "aggs" => [
                    "avg" => [
                        "avg_bucket" => [
                            "buckets_path" => "1-bucket>1-metric"
                        ]
                    ],
                    "1-bucket" => [
                        "date_histogram" => [
                            "field" => "@timestamp",
                            "interval" => $interval,
                            "min_doc_count" => 1
                        ],
                        "aggs" => [
                            "1-metric" => [
                                "cardinality" => [
                                    "field" => "from"
                                ]
                            ]
                        ]
                    ]
                ],
            ]
        ];

        $prepared = new ElasticSearch\Prepared\Search();
        $prepared->query($query);

        $result = $this->client->request($prepared);

        return $result['aggregations']['avg']['value'] ?? 0;
    }

    private function getGraph($from, $to, $interval)
    {
        $must = [
            [
                "match_all" => (object) []
            ],
            [
                "range" => [
                    "@timestamp" => [
                        "gte" => $from->getTimestamp() * 1000,
                        "lte" => $to->getTimestamp() * 1000,
                        "format" => "epoch_millis"
                    ]
                ]
            ],
            [
                "match_phrase" => [
                    "transactionCategory" => [
                        "query" => "boost"
                    ]
                ]
            ],
            [
                "match_phrase" => [
                    "isTokenTransaction" => [
                        "query" => true
                    ]
                ]
            ],
            [
                "match_phrase" => [
                    "function" => [
                        "query" => "approveAndCall"
                    ]
                ]
            ]
        ];

        $query = [
            'index' => $this->index,
            'size' => 0,
            "stored_fields" => [
                "*"
            ],
            "docvalue_fields" => [
                (object) [
                    "field" => "@timestamp",
                    "format" => "date_time"
                ]
            ],
            'body' => [
                'query' => [
                    'bool' => [
                        'must' => $must
                    ]
                ],
                "aggs" => [
                    "histogram" => [
                        "date_histogram" => [
                            "field" => "@timestamp",
                            "interval" => $interval,
                            "min_doc_count" => 1
                        ],
                        "aggs" => [
                            "sums" => [
                                "sum" => [
                                    "field" => "tokenValue"
                                ]
                            ],
                            "unique" => [
                                "cardinality" => [
                                    "field" => "from"
                                ]
                            ]
                        ]
                    ]
                ],
            ]
        ];

        $prepared = new ElasticSearch\Prepared\Search();
        $prepared->query($query);

        $result = $this->client->request($prepared);

        $response = [
            [
                'name' => 'Reclaimed Tokens from OnChain Boosts',
                'x' => [],
                'y' => [],
            ],
            [
                'name' => 'Number of OnChain Boosts Transactions',
                'x' => [],
                'y' => [],
            ],
            [
                'name' => 'Number of Users that used OnChain Boosts',
                'x' => [],
                'y' => [],
            ],
        ];

        foreach ($result['aggregations']['histogram']['buckets'] as $count) {
            $date = date($this->dateFormat, $count['key'] / 1000);

            $response[0]['x'][] = $date;
            $response[0]['y'][] = $count['sums']['value'] ?? 0;

            $response[1]['x'][] = $date;
            $response[1]['y'][] = $count['doc_count'] ?? 0;

            $response[2]['x'][] = $date;
            $response[2]['y'][] = $count['unique']['value'] ?? 0;
        }

        return $response;
    }
}
