<?php

namespace App\Models;

use CodeIgniter\Model;

class UsersModel extends Model {
    protected $table = 'users';

    protected $primaryKey = 'id';

    protected $allowedFields = ['id', 'names', 'phone', 'userType', 'password'];
    protected $useTimestamps = true;
	protected $createdField  = 'created_at';
	protected $updatedField  = 'updated_at';
}

