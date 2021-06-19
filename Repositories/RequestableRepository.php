<?php

namespace Modules\Requestable\Repositories;

use Modules\Core\Repositories\BaseRepository;

interface RequestableRepository extends BaseRepository
{
  
  public function getItemsBy($params);
  
  public function getItem($criteria, $params);
  
  public function create($data);
  
  public function updateBy($criteria, $data, $params);
  
  public function deleteBy($criteria, $params);
  
  public function moduleConfigs();
}
