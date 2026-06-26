/**
 * KESA Learn - Razorpay Payment Handler
 */

document.addEventListener('DOMContentLoaded', function() {
    const razorpayBtn = document.getElementById('razorpay-btn');
    if (!razorpayBtn) return;

    const keyId = document.querySelector('meta[name="razorpay-key"]')?.content;
    if (!keyId) {
        console.error('Razorpay key not found');
        return;
    }

    razorpayBtn.addEventListener('click', function() {
        const btn = this;
        btn.disabled = true;
        btn.textContent = 'Processing...';

        const amount = btn.dataset.amount;
        const currency = btn.dataset.currency;
        const description = btn.dataset.description;
        const registrationId = btn.dataset.registrationId;
        const email = btn.dataset.email;
        const phone = btn.dataset.phone;
        const userName = btn.dataset.userName;

        // Create Razorpay order via server
        fetch('/api/razorpay_webhook.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'create_order',
                registration_id: registrationId,
                amount: amount
            })
        })
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                alert(data.message || 'Failed to create order.');
                btn.disabled = false;
                btn.textContent = 'Retry Payment';
                return;
            }

            const options = {
                key: keyId,
                amount: amount,
                currency: currency,
                name: 'KESA Learn',
                description: description,
                order_id: data.order_id,
                prefill: {
                    name: userName,
                    email: email,
                    contact: phone
                },
                theme: {
                    color: '#e7404a'
                },
                handler: function(response) {
                    // Verify payment on server
                    fetch('/api/razorpay_webhook.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action: 'verify_payment',
                            registration_id: registrationId,
                            razorpay_order_id: response.razorpay_order_id,
                            razorpay_payment_id: response.razorpay_payment_id,
                            razorpay_signature: response.razorpay_signature
                        })
                    })
                    .then(r => r.json())
                    .then(verifyData => {
                        if (verifyData.success) {
                            // Redirect to professional success popup page
                            const paymentId = response.razorpay_payment_id;
                            const redirectUrl = '/events/payment-success.php?payment_id=' + paymentId + '&registration_id=' + registrationId;
                            window.location.href = redirectUrl;
                        } else {
                            alert('Payment verification failed. Please contact support.');
                            window.location.href = '/user/dashboard';
                        }
                    })
                    .catch(() => {
                        alert('Payment verification error. Please contact support.');
                        window.location.href = '/user/dashboard';
                    });
                },
                modal: {
                    ondismiss: function() {
                        btn.disabled = false;
                        btn.textContent = 'Pay ' + (amount / 100).toFixed(2) + ' with Razorpay';
                    }
                }
            };

            const rzp = new Razorpay(options);
            rzp.on('payment.failed', function(response) {
                alert('Payment failed: ' + response.error.description);
                btn.disabled = false;
                btn.textContent = 'Retry Payment';
            });
            rzp.open();
        })
        .catch(err => {
            console.error('Error:', err);
            alert('Something went wrong. Please try again.');
            btn.disabled = false;
            btn.textContent = 'Retry Payment';
        });
    });
});
