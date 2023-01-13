<?php

namespace App\Models;

use CodeIgniter\Model;

class CategoriesModel extends Model {
    protected $table = 'categories';

    protected $primaryKey = 'id';

    protected $allowedFields = ['id', 'title'];
    protected $useTimestamps = false;
}

