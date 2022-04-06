<?php

namespace WonderGame\EsUtility\HttpController\Admin;

use EasySwoole\Mysqli\QueryBuilder;
use EasySwoole\ORM\AbstractModel;
use WonderGame\EsUtility\Common\Http\Code;

trait HttpTrackerTrait
{
    protected function _search()
    {
        if (empty($this->get['where']))
        {
            // 默认最近14天
            $tomorrow = strtotime('tomorrow');
            $begintime = $tomorrow - (14 * 86400);
            $endtime = $tomorrow - 1;
            $this->Model->where('instime', [$begintime, $endtime], 'BETWEEN');
        } else {
            $this->Model->where($this->get['where']);
        }
        return null;

        // 已废弃
        return function (QueryBuilder $builder) {
            $filter = $this->filter();
            $builder->where('instime', [$filter['begintime'], $filter['endtime']], 'between');
            if (isset($filter['repeated']) && $filter['repeated'] !== '')
            {
                $builder->where('repeated', $filter['repeated']);
            }

            // envkey: {"one":"point_name","two":"point_id"}
            // envvalue: {"one":"123","two":"4556"}
            foreach (['envkey', 'envvalue'] as $col)
            {
                if (!empty($filter[$col])) {
                    $filter[$col] = json_decode($filter[$col], true);
                }
            }

            if (!empty($filter['envkey']))
            {
                foreach ($filter['envkey'] as $key => $value)
                {
                    if ($like = $filter['envvalue'][$key])
                    {
                        $calc = true;
                        // 支持逻辑运算转换为like
                        $symbol = ['&&' => ' AND ', '||' => ' OR '];
                        foreach ($symbol as $sym => $join)
                        {
                            if (strpos($like, $sym) !== false)
                            {
                                $tmp = [];
                                $arr = explode($sym, $like);
                                foreach ($arr as $item)
                                {
                                    $item && $tmp[] = "$value LIKE '%{$item}%'";
                                }
                                if ($tmp) {
                                    $tmp = implode($join, $tmp);
                                    $builder->where("($tmp)");
                                    $calc = false;
                                }
                            }
                        }
                        if ($calc) {
                            $builder->where($value, "%{$like}%", 'LIKE');
                        }
                    }
                }
            }

            $runtime = $filter['runtime'] ?? 0;
            if ($runtime > 0)
            {
                $builder->where('runtime', $runtime, '>=');
            }
            /*
             * 生成的SQL分析示例
             * explain partitions SELECT SQL_CALC_FOUND_ROWS * FROM `http_tracker` WHERE  `instime` between 1646197200 AND 1647493199  AND `point_name` LIKE '%123%'  AND (point_id LIKE '%4556%' AND point_id LIKE '%789%') ORDER BY instime DESC  LIMIT 0, 100\G
             * */
        };
    }

    // 单条复发
    public function repeat()
    {
        $pointId = $this->post['pointId'];
        if (empty($pointId))
        {
            return $this->error(Code::ERROR_OTHER, 'PointId id empty.');
        }
        $row = $this->Model->where('point_id', $pointId)->get();
        if (!$row)
        {
            return $this->error(Code::ERROR_OTHER, 'PointId id Error: ' . $pointId);
        }

        $response = $row->repeatOne();
        if (!$response)
        {
            $this->error(Code::ERROR_OTHER, 'Http Error! ');
        } else {
            $this->success([
                'httpStatusCode' => $response->getStatusCode(),
                'data' => json_decode($response->getBody(), true)
            ]);
        }
    }

    // 试运行，查询count
    public function count()
    {
        $where = $this->post['where'];
        if (empty($where))
        {
            return $this->error(Code::ERROR_OTHER, 'ERROR is Empty');
        }
        try {
            $count = $this->Model->where($where)->count('point_id');
            $this->success(['count' => $count]);
        }
        catch (\Exception | \Throwable $e)
        {
            $this->error(Code::ERROR_OTHER, $e->getMessage());
        }
    }

    // 确定运行
    public function run()
    {
        $where = $this->post['where'];
        if (empty($where))
        {
            return $this->error(Code::ERROR_OTHER, 'run ERROR is Empty');
        }
        try {
            $count = $this->Model->where($where)->count('point_id');
            if ($count <= 0) {
                return $this->error(Code::ERROR_OTHER, 'COUNT行数为0');
            }
            $task = \EasySwoole\EasySwoole\Task\TaskManager::getInstance();
//            $status = $task->async(new \App\Task\HttpTracker([
//                'count' => $count,
//                'where' => $where
//            ]));
            $status = $task->async(function () use ($where) {
                trace('HttpTracker 开始 ');

                /** @var AbstractModel $model */
                $model = model('HttpTracker');
                $model->where($where)->chunk(function ($item) {
                    $item->repeatOne();
                }, 300);
                trace('HttpTracker 结束 ');
            });
            if ($status > 0) {
                $this->success(['count' => $count, 'task' => $status]);
            } else {
                $this->error(Code::ERROR_OTHER, "投递异步任务失败: $status");
            }
        }
        catch (\Exception | \Throwable $e)
        {
            $this->error(Code::ERROR_OTHER, $e->getMessage());
        }
    }
}
