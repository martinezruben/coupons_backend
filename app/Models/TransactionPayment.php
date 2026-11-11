<?php

namespace App\Models;

use App\Models\Traits\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransactionPayment extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    protected $fillable = ['transaction_id', 'payment_method', 'amount', 'reference'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }
}
