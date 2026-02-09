<?php

namespace App\Models;

use CodeIgniter\Model;

class CouponModel extends Model
{
    protected $table            = 'coupons';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'object';
    protected $useSoftDeletes   = false; // Pode mudar para true se add deleted_at
    protected $protectFields    = true;
    protected $allowedFields    = [
        'code', 'description', 'discount_type', 'discount_value', 
        'max_uses', 'used_count', 'valid_from', 'valid_until', 'is_active'
    ];

    // Dates
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    // protected $deletedField  = 'deleted_at';

    // Validation
    protected $validationRules      = [
        'code'           => 'required|min_length[3]|is_unique[coupons.code,id,{id}]',
        'discount_type'  => 'required|in_list[percent,fixed]',
        'discount_value' => 'required|numeric|greater_than[0]',
    ];
    protected $validationMessages   = [];
    protected $skipValidation       = false;
    protected $cleanValidationRules = true;
    
    /**
     * Verifica e retorna o cupom se for válido
     * @param string $code
     * @return object|null
     */
    public function getValidCoupon(string $code)
    {
        $now = date('Y-m-d H:i:s');
        
        $coupon = $this->where('code', $code)
                       ->where('is_active', true)
                       ->groupStart() // (valid_from IS NULL OR valid_from <= NOW)
                           ->where('valid_from', null)
                           ->orWhere('valid_from <=', $now)
                       ->groupEnd()
                       ->groupStart() // (valid_until IS NULL OR valid_until >= NOW)
                           ->where('valid_until', null)
                           ->orWhere('valid_until >=', $now)
                       ->groupEnd()
                       ->first();
                       
        if (!$coupon) {
            return null;
        }
        
        // Check Usage Limits
        if (!is_null($coupon->max_uses) && $coupon->used_count >= $coupon->max_uses) {
            return null;
        }
        
        return $coupon;
    }

    /**
     * Incrementa o uso do cupom e registra log
     */
    public function registerUsage($couponId, $accountId, $transactionId, $discountAmount)
    {
        // Update Counter
        $this->set('used_count', 'used_count + 1', false)
             ->update($couponId);

        // Insert Log
        $db = \Config\Database::connect();
        $db->table('coupon_usages')->insert([
            'coupon_id' => $couponId,
            'account_id' => $accountId,
            'transaction_id' => $transactionId,
            'discount_applied' => $discountAmount,
            'used_at' => date('Y-m-d H:i:s')
        ]);
    }
}
