<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\I18n\Time;

class NotificationController extends BaseController
{
    /**
     * Retorna notificações não lidas e contador em JSON
     */
    public function getLatest()
    {
        if (!auth()->loggedIn()) {
            return $this->response->setStatusCode(401);
        }

        $userId = auth()->id();
        $model = model('App\Models\NotificationModel');

        $unreadCount = $model->countUnread($userId);
        $notifications = $model->getUnread($userId, 5); // Traz as 5 últimas

        // Formata para JSON (ou constrói HTML aqui mesmo se preferir, 
        // mas vamos mandar dados para o JS renderizar e ficar leve)
        $data = [];
        foreach ($notifications as $notif) {
            $data[] = [
                'id' => $notif->id,
                'title' => $notif->title,
                'message' => $notif->message,
                'icon_class' => $notif->getIconClass(),
                'link' => $notif->link,
                'time_ago' => Time::parse($notif->created_at)->humanize(),
                'type' => $notif->type
            ];
        }

        return $this->response->setJSON([
            'unread_count' => $unreadCount,
            'notifications' => $data
        ]);
    }

    /**
     * Marca uma notificação como lida
     */
    public function markAsRead($id)
    {
        if (!auth()->loggedIn()) return $this->response->setStatusCode(401);

        $model = model('App\Models\NotificationModel');
        $notif = $model->find($id);

        if (!$notif || $notif->user_id != auth()->id()) {
            return $this->response->setJSON(['success' => false]);
        }

        $notif->read_at = date('Y-m-d H:i:s');
        $model->save($notif);

        return $this->response->setJSON(['success' => true]);
    }

    /**
     * Marca todas como lidas
     */
    public function markAllAsRead()
    {
        if (!auth()->loggedIn()) return $this->response->setStatusCode(401);

        $model = model('App\Models\NotificationModel');
        $model->where('user_id', auth()->id())
              ->where('read_at', null)
              ->set(['read_at' => date('Y-m-d H:i:s')])
              ->update();

        return $this->response->setJSON(['success' => true]);
    }
}
