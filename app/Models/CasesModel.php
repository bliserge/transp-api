<?php

namespace App\Models;

use CodeIgniter\Model;

class CasesModel extends Model {
    protected $table = 'cases';

    protected $primaryKey = 'id';

    protected $allowedFields = ['id', 'names', 'phone', 'id_number', 'categoryId', 'problem', 'attorney', 'status'];
    protected $useTimestamps = true;
	protected $createdField  = 'created_at';
	protected $updatedField  = 'updated_at';
}

