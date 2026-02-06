<?php

namespace App\Models;

use CodeIgniter\Model;
use App\Entities\Notification;

class NotificationModel extends Model
{
    protected $table            = 'notifications';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = Notification::class;
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'user_id', 'account_id', 'title', 'message', 'link', 'type', 'read_at'
    ];

    // Dates
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $deletedField  = 'deleted_at';

    /**
     * Busca notificações não lidas de um usuário.
     */
    public function getUnread(int $userId, int $limit = 5)
    {
        return $this->where('user_id', $userId)
                    ->where('read_at', null)
                    ->orderBy('created_at', 'DESC')
                    ->findAll($limit);
    }
    
    /**
     * Conta notificações não lidas.
     */
    public function countUnread(int $userId): int
    {
        return $this->where('user_id', $userId)
                    ->where('read_at', null)
                    ->countAllResults();
    }
}
