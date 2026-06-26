/**
 * KESA Learn - Main JavaScript
 */

document.addEventListener('DOMContentLoaded', function() {

    /* =====================================================
       Mobile Navigation Toggle
       ===================================================== */
    const navToggle = document.querySelector('.nav-toggle');
    const navLinks = document.querySelector('.nav-links');
    
    if (navToggle && navLinks) {
        navToggle.addEventListener('click', function() {
            navLinks.classList.toggle('active');
            this.classList.toggle('active');
        });
        
        // Close menu on link click
        navLinks.querySelectorAll('a').forEach(link => {
            link.addEventListener('click', () => {
                navLinks.classList.remove('active');
                navToggle.classList.remove('active');
            });
        });
    }

    /* =====================================================
       Navbar Scroll Effect
       ===================================================== */
    const navbar = document.querySelector('.navbar');
    if (navbar) {
        window.addEventListener('scroll', function() {
            if (window.scrollY > 50) {
                navbar.classList.add('scrolled');
            } else {
                navbar.classList.remove('scrolled');
            }
        });
    }

    /* =====================================================
       Banner Carousel
       ===================================================== */
    const carousel = document.querySelector('.banner-carousel');
    if (carousel) {
        const slides = carousel.querySelectorAll('.banner-slide');
        const dots = carousel.querySelectorAll('.banner-dot');
        const carouselSpeed = parseInt(carousel.dataset.speed) || 5000;
        let currentSlide = 0;
        let interval;

        function showSlide(index) {
            slides.forEach(s => s.classList.remove('active'));
            dots.forEach(d => d.classList.remove('active'));
            
            currentSlide = index;
            if (currentSlide >= slides.length) currentSlide = 0;
            if (currentSlide < 0) currentSlide = slides.length - 1;
            
            slides[currentSlide].classList.add('active');
            if (dots[currentSlide]) dots[currentSlide].classList.add('active');
        }

        function startAutoplay() {
            interval = setInterval(() => showSlide(currentSlide + 1), carouselSpeed);
        }

        function stopAutoplay() {
            clearInterval(interval);
        }

        dots.forEach((dot, i) => {
            dot.addEventListener('click', () => {
                stopAutoplay();
                showSlide(i);
                startAutoplay();
            });
        });

        if (slides.length > 1) {
            startAutoplay();
        }
    }

    /* =====================================================
       Countdown Timer
       ===================================================== */
    const countdownElements = document.querySelectorAll('[data-countdown]');
    
    countdownElements.forEach(el => {
        const targetDate = new Date(el.dataset.countdown).getTime();
        
        function updateCountdown() {
            const now = new Date().getTime();
            const diff = targetDate - now;
            
            if (diff <= 0) {
                el.innerHTML = '<div class="countdown-expired">Event has started!</div>';
                return;
            }
            
            const days = Math.floor(diff / (1000 * 60 * 60 * 24));
            const hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((diff % (1000 * 60)) / 1000);
            
            el.querySelector('.days .number').textContent = String(days).padStart(2, '0');
            el.querySelector('.hours .number').textContent = String(hours).padStart(2, '0');
            el.querySelector('.minutes .number').textContent = String(minutes).padStart(2, '0');
            el.querySelector('.seconds .number').textContent = String(seconds).padStart(2, '0');
        }
        
        updateCountdown();
        setInterval(updateCountdown, 1000);
    });

    /* =====================================================
       Animated Statistics Counter
       ===================================================== */
    const statNumbers = document.querySelectorAll('[data-count]');
    
    if (statNumbers.length > 0) {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const target = parseInt(entry.target.dataset.count);
                    const suffix = entry.target.dataset.suffix || '';
                    animateCount(entry.target, target, suffix);
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.5 });
        
        statNumbers.forEach(el => observer.observe(el));
    }

    function animateCount(element, target, suffix) {
        let current = 0;
        const increment = target / 60;
        const timer = setInterval(() => {
            current += increment;
            if (current >= target) {
                current = target;
                clearInterval(timer);
            }
            element.textContent = Math.floor(current).toLocaleString() + suffix;
        }, 25);
    }

    /* =====================================================
       Flash Message Auto-dismiss
       ===================================================== */
    const flashMessages = document.querySelectorAll('.flash-message');
    flashMessages.forEach(flash => {
        setTimeout(() => {
            flash.style.opacity = '0';
            flash.style.transform = 'translateY(-10px)';
            setTimeout(() => flash.remove(), 300);
        }, 5000);
    });

    /* =====================================================
       Smooth Scroll for Anchor Links
       ===================================================== */
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function(e) {
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                e.preventDefault();
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });

    /* =====================================================
       Event Filter Buttons
       ===================================================== */
    const filterBtns = document.querySelectorAll('.filter-btn[data-filter]');
    const filterableCards = document.querySelectorAll('[data-type]');
    
    filterBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            filterBtns.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            
            const filter = this.dataset.filter;
            
            filterableCards.forEach(card => {
                if (filter === 'all' || card.dataset.type === filter) {
                    card.style.display = '';
                    card.style.animation = 'fadeIn 0.3s ease';
                } else {
                    card.style.display = 'none';
                }
            });
        });
    });

    /* =====================================================
       File Upload Preview
       ===================================================== */
    document.querySelectorAll('.file-upload-input').forEach(input => {
        input.addEventListener('change', function() {
            const preview = this.closest('.file-upload').querySelector('.file-preview');
            const file = this.files[0];
            
            if (file && preview) {
                if (file.type.startsWith('image/')) {
                    const reader = new FileReader();
                    reader.onload = (e) => {
                        preview.innerHTML = '<img src="' + e.target.result + '" alt="Preview">';
                        preview.style.display = 'block';
                    };
                    reader.readAsDataURL(file);
                } else {
                    preview.innerHTML = '<p>' + file.name + ' (' + formatFileSize(file.size) + ')</p>';
                    preview.style.display = 'block';
                }
            }
        });
    });

    function formatFileSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1048576).toFixed(1) + ' MB';
    }

    /* =====================================================
       Seat Availability Check (AJAX)
       ===================================================== */
    const seatChecks = document.querySelectorAll('[data-check-seats]');
    seatChecks.forEach(el => {
        const eventId = el.dataset.checkSeats;
        
        function checkSeats() {
            fetch('/api/check_seat.php?event_id=' + eventId)
                .then(r => r.json())
                .then(data => {
                    const seatsEl = el.querySelector('.seats-text');
                    const barFill = el.querySelector('.seats-bar-fill');
                    
                    if (seatsEl && data.max_seats) {
                        const remaining = data.max_seats - data.seats_taken;
                        seatsEl.innerHTML = '<strong>' + remaining + '</strong> seats remaining out of ' + data.max_seats;
                        
                        const pct = (data.seats_taken / data.max_seats) * 100;
                        if (barFill) {
                            barFill.style.width = pct + '%';
                            barFill.className = 'seats-bar-fill';
                            if (pct > 80) barFill.classList.add('almost-full');
                            else if (pct > 50) barFill.classList.add('filling');
                        }
                    }
                })
                .catch(() => {});
        }
        
        checkSeats();
        setInterval(checkSeats, 30000); // Refresh every 30 seconds
    });

    /* =====================================================
       Confirm Delete
       ===================================================== */
    document.querySelectorAll('[data-confirm]').forEach(el => {
        el.addEventListener('click', function(e) {
            if (!confirm(this.dataset.confirm || 'Are you sure?')) {
                e.preventDefault();
            }
        });
    });

    /* =====================================================
       Info Tooltip Click/Tap Support
       ===================================================== */
    document.addEventListener('click', function(e) {
        // Close all open tooltips when clicking outside
        if (!e.target.closest('.info-tooltip')) {
            document.querySelectorAll('.info-tooltip.active').forEach(function(t) {
                t.classList.remove('active');
            });
        }
    });

    /* =====================================================
       Password Toggle
       ===================================================== */
    document.querySelectorAll('.password-toggle').forEach(btn => {
        btn.addEventListener('click', function() {
            const input = this.previousElementSibling;
            if (input.type === 'password') {
                input.type = 'text';
                this.textContent = 'Hide';
            } else {
                input.type = 'password';
                this.textContent = 'Show';
            }
        });
    });

});
