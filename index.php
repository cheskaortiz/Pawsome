<?php
$currentYear = date('Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Pawsome makes it easy to book your pet's grooming appointments and keep their care records in one friendly place.">
    <meta name="theme-color" content="#fbf8ff">
    <title>Pawsome | A little extra love for your pet</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Nunito:wght@500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/about.css">
    <link rel="stylesheet" href="assets/css/booking.css">
    <link rel="stylesheet" href="assets/css/prices.css">
</head>
<body>
    <a class="skip-link" href="#main-content">Skip to content</a>

    <header class="site-header">
        <div class="container header-inner">
            <a class="brand" href="index.php" aria-label="Pawsome home">
                <span class="brand-mark" aria-hidden="true">
                    <svg viewBox="0 0 40 40" focusable="false">
                        <path d="M20 31.3c-1.6 0-3.1-1.1-5.4-1.1-2.1 0-3.6 1-5.3.2-2.4-1.2-2.1-4.7.6-7.5 2.4-2.5 6-4.4 10.1-4.4s7.7 1.9 10.1 4.4c2.7 2.8 3 6.3.6 7.5-1.7.8-3.2-.2-5.3-.2-2.3 0-3.8 1.1-5.4 1.1Z" fill="currentColor"/>
                        <ellipse cx="9.8" cy="15.3" rx="3.3" ry="4.3" transform="rotate(-27 9.8 15.3)" fill="currentColor"/>
                        <ellipse cx="17.1" cy="10.6" rx="3.2" ry="4.2" transform="rotate(-10 17.1 10.6)" fill="currentColor"/>
                        <ellipse cx="24.9" cy="10.6" rx="3.2" ry="4.2" transform="rotate(10 24.9 10.6)" fill="currentColor"/>
                        <ellipse cx="32.2" cy="15.3" rx="3.3" ry="4.3" transform="rotate(27 32.2 15.3)" fill="currentColor"/>
                    </svg>
                </span>
                <span class="brand-name">Paw<span>some</span></span>
            </a>

            <nav class="main-nav" aria-label="Main navigation">
                <a class="nav-link active" href="index.php" aria-current="page">Home</a>
                <a class="nav-link" href="#services">Services</a>
                <a class="nav-link" href="#about">About Us</a>
                <a class="nav-link" href="#booking">Booking</a>
                <a class="nav-link" href="#prices">Prices</a>
                <a class="nav-link nav-login" href="customer/login.php">Log In</a>
                <a class="button button-small button-primary nav-register" href="customer/register.php">Register</a>
            </nav>
        </div>
    </header>

    <main id="main-content">
        <section class="hero section-shell">
            <div class="hero-shop" aria-hidden="true">
                <div class="shop-window"></div>
                <div class="shop-shelf shop-shelf-left"><span></span><span></span><span></span></div>
                <div class="shop-shelf shop-shelf-right"><span></span><span></span><span></span></div>
            </div>
            <div class="container hero-container">
                <form class="hero-search" id="service-search-form" action="#services" role="search">
                    <div class="hero-search-field">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.8"/><path d="m16 16 5 5"/></svg>
                        <label class="sr-only" for="service-search-input">Search grooming services</label>
                        <input id="service-search-input" type="search" name="query" placeholder="Search for pet grooming services..." autocomplete="off">
                    </div>
                    <label class="sr-only" for="service-category">Service category</label>
                    <select class="hero-search-category" id="service-category" name="category">
                        <option value="all">All Categories</option>
                        <option value="bath">Bath and Blow Dry</option>
                        <option value="haircut">Haircut and Styling</option>
                        <option value="nails">Nail Trimming</option>
                        <option value="ear">Ear Cleaning</option>
                        <option value="full">Full Grooming</option>
                    </select>
                    <button class="hero-search-submit" type="submit" aria-label="Search services">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.8"/><path d="m16 16 5 5"/></svg>
                    </button>
                </form>

                <div class="hero-grid">
                    <figure class="hero-pet hero-pet-cat">
                        <img src="assets/images/cat-happy.png" alt="A friendly black-and-white cat resting its paws on a ledge">
                        <figcaption>Cat-friendly care</figcaption>
                    </figure>

                    <div class="hero-copy">
                        <span class="eyebrow"><span class="eyebrow-dot"></span> A happier grooming day starts here</span>
                        <h1>Fresh coats.<br><span>Happy hearts.</span></h1>
                        <p class="hero-description">A little less planning for you, a lot more pampering for them. Find thoughtful grooming care for every kind of coat.</p>
                        <div class="hero-actions">
                            <a class="button button-primary" href="customer/appointments.php">Book an Appointment <span aria-hidden="true">→</span></a>
                            <a class="text-link" href="#services">Explore services <span aria-hidden="true">↓</span></a>
                        </div>
                        <div class="hero-note">
                            <span class="note-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><path d="M12 20s-7-4.4-7-10.1A3.9 3.9 0 0 1 12 7a3.9 3.9 0 0 1 7 2.9C19 15.6 12 20 12 20Z"/><path d="M8.8 12.1h1.7l1-2.1 1.8 4 1-1.9h1.2"/></svg>
                            </span>
                            <span>Thoughtful care for every kind of coat</span>
                        </div>
                    </div>

                    <figure class="hero-pet hero-pet-dog">
                        <img src="assets/images/dog-happy.png" alt="A gentle golden retriever enjoying a sunny day">
                        <figcaption>Good-dog grooming</figcaption>
                    </figure>
                </div>
            </div>
        </section>

        <section class="services section-padding" id="services">
            <div class="container">
                <div class="section-heading">
                    <span class="eyebrow">A little something for every pet</span>
                    <h2>Care that fits <span>their style</span></h2>
                    <p>From a quick freshen-up to a full fluff-and-polish, choose the care that feels right for your best friend.</p>
                </div>
                <div class="service-grid">
                    <article class="service-card" data-category="bath">
                        <div class="service-icon icon-lilac" aria-hidden="true">
                            <svg viewBox="0 0 48 48"><path d="M15 10h18l-2 7H17l-2-7Z"/><path d="M12 21h24l-3 19H15l-3-19Z"/><path d="M20 26v8m8-8v8"/><path d="M10 15h28"/></svg>
                        </div>
                        <h3>Basic Bath</h3>
                        <p>A warm bath, gentle shampoo, and a fluffy towel-dry for a fresh, happy coat.</p>
                        <span class="service-detail">A lovely little refresh</span>
                    </article>
                    <article class="service-card" data-category="full">
                        <div class="service-icon icon-peach" aria-hidden="true">
                            <svg viewBox="0 0 48 48"><path d="M14 12c-3 3-4 8-3 13 1 8 6 13 13 13s12-5 13-13c1-5 0-10-3-13"/><path d="M16 12c2 4 5 6 8 6s6-2 8-6"/><path d="M18 28h.1m11.8 0h.1M21 33c2 2 4 2 6 0"/><path d="M19 11c1-3 3-4 5-4s4 1 5 4"/></svg>
                        </div>
                        <h3>Full Grooming</h3>
                        <p>Bath, brush, tidy-up, and all the finishing touches for head-to-paw care.</p>
                        <span class="service-detail">Our feel-good full treatment</span>
                    </article>
                    <article class="service-card" data-category="haircut">
                        <div class="service-icon icon-yellow" aria-hidden="true">
                            <svg viewBox="0 0 48 48"><path d="M17 11c0 8-4 12-8 17l7 7c5-4 9-8 17-8"/><path d="m18 31 13-13m-8 16 13-13"/><circle cx="13" cy="17" r="4"/><circle cx="34" cy="38" r="4"/></svg>
                        </div>
                        <h3>Haircut &amp; Styling</h3>
                        <p>A neat, comfortable trim shaped around your pet's coat and personality.</p>
                        <span class="service-detail">Their coat, their kind of cute</span>
                    </article>
                    <article class="service-card" data-category="nails">
                        <div class="service-icon icon-mint" aria-hidden="true">
                            <svg viewBox="0 0 48 48"><path d="m12 35 20-20 5 5-20 20-5-5Z"/><path d="m28 18 5 5"/><path d="m13 34-3 7 7-3"/><path d="M13 14h10m-5-5v10"/><path d="M33 31h7m-3.5-3.5v7"/></svg>
                        </div>
                        <h3>Nail Trimming</h3>
                        <p>A careful clip to help keep those little paws comfy, tidy, and ready to roam.</p>
                        <span class="service-detail">Small detail, big comfort</span>
                    </article>
                </div>
                <p class="service-search-empty" id="service-search-empty" role="status" hidden>No matching services found. Try another search or category.</p>
                <p class="service-note">Our listed services are examples. Details and availability can be confirmed when booking.</p>
            </div>
        </section>

        <?php require __DIR__ . '/includes/about.php'; ?>
        <?php require __DIR__ . '/includes/booking.php'; ?>
        <?php require __DIR__ . '/includes/prices.php'; ?>

        <section class="benefits section-padding">
            <div class="container">
                <div class="section-heading">
                    <span class="eyebrow">Made for you and your best friend</span>
                    <h2>One happy place for <span>pet care</span></h2>
                </div>
                <div class="benefit-grid">
                    <article class="benefit-item">
                        <span class="benefit-number">01</span>
                        <div class="benefit-icon" aria-hidden="true">
                            <svg viewBox="0 0 48 48"><rect x="10" y="13" width="28" height="27" rx="5"/><path d="M16 9v8m16-8v8M10 21h28m-21 7 4 4 8-8"/></svg>
                        </div>
                        <h3>Convenient booking</h3>
                        <p>Request a grooming appointment without the back-and-forth.</p>
                    </article>
                    <article class="benefit-item">
                        <span class="benefit-number">02</span>
                        <div class="benefit-icon" aria-hidden="true">
                            <svg viewBox="0 0 48 48"><path d="M15 9h18a3 3 0 0 1 3 3v27H12V12a3 3 0 0 1 3-3Z"/><path d="M18 9V7h12v2m-11 9h10m-10 6h12m-12 6h8"/></svg>
                        </div>
                        <h3>Organized pet records</h3>
                        <p>Keep the important details of your pet's grooming care together.</p>
                    </article>
                    <article class="benefit-item">
                        <span class="benefit-number">03</span>
                        <div class="benefit-icon" aria-hidden="true">
                            <svg viewBox="0 0 48 48"><circle cx="24" cy="25" r="15"/><path d="M24 16v10l7 4M18 7h12"/></svg>
                        </div>
                        <h3>Easy appointment tracking</h3>
                        <p>Check in on your appointment details and keep plans clear.</p>
                    </article>
                    <article class="benefit-item">
                        <span class="benefit-number">04</span>
                        <div class="benefit-icon" aria-hidden="true">
                            <svg viewBox="0 0 48 48"><path d="M24 39s-15-9.4-15-21.6A8.3 8.3 0 0 1 24 11a8.3 8.3 0 0 1 15 6.4C39 29.6 24 39 24 39Z"/><path d="M15 24h5l2-4 4 9 2-5h5"/></svg>
                        </div>
                        <h3>Pet-focused care</h3>
                        <p>Thoughtful grooming that puts your pet's comfort first.</p>
                    </article>
                </div>
            </div>
        </section>

        <section class="cta-section">
            <div class="container">
                <div class="cta-card">
                    <span class="cta-decoration cta-decoration-one" aria-hidden="true">✦</span>
                    <span class="cta-decoration cta-decoration-two" aria-hidden="true">✧</span>
                    <div class="cta-copy">
                        <span class="eyebrow eyebrow-light">Their next fresh-and-fluffy day awaits</span>
                        <h2>Ready for a little<br>more <span>tail-wagging?</span></h2>
                        <p>Create your Pawsome account and make your pet's next grooming day easy to plan.</p>
                        <div class="cta-actions">
                            <a class="button button-white" href="customer/register.php">Join Pawsome <span aria-hidden="true">→</span></a>
                            <a class="cta-login-link" href="customer/login.php">Already have an account? Log in</a>
                        </div>
                    </div>
                    <div class="cta-paw-art" aria-hidden="true">
                        <svg viewBox="0 0 180 170">
                            <ellipse cx="90" cy="119" rx="42" ry="31" fill="currentColor"/>
                            <ellipse cx="43" cy="76" rx="18" ry="24" transform="rotate(-25 43 76)" fill="currentColor"/>
                            <ellipse cx="78" cy="48" rx="18" ry="25" transform="rotate(-8 78 48)" fill="currentColor"/>
                            <ellipse cx="113" cy="48" rx="18" ry="25" transform="rotate(8 113 48)" fill="currentColor"/>
                            <ellipse cx="146" cy="76" rx="18" ry="24" transform="rotate(25 146 76)" fill="currentColor"/>
                        </svg>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <footer class="site-footer">
        <div class="container footer-main">
            <div class="footer-brand-block">
                <a class="brand footer-brand" href="index.php" aria-label="Pawsome home">
                    <span class="brand-mark" aria-hidden="true">
                        <svg viewBox="0 0 40 40" focusable="false">
                            <path d="M20 31.3c-1.6 0-3.1-1.1-5.4-1.1-2.1 0-3.6 1-5.3.2-2.4-1.2-2.1-4.7.6-7.5 2.4-2.5 6-4.4 10.1-4.4s7.7 1.9 10.1 4.4c2.7 2.8 3 6.3.6 7.5-1.7.8-3.2-.2-5.3-.2-2.3 0-3.8 1.1-5.4 1.1Z" fill="currentColor"/>
                            <ellipse cx="9.8" cy="15.3" rx="3.3" ry="4.3" transform="rotate(-27 9.8 15.3)" fill="currentColor"/>
                            <ellipse cx="17.1" cy="10.6" rx="3.2" ry="4.2" transform="rotate(-10 17.1 10.6)" fill="currentColor"/>
                            <ellipse cx="24.9" cy="10.6" rx="3.2" ry="4.2" transform="rotate(10 24.9 10.6)" fill="currentColor"/>
                            <ellipse cx="32.2" cy="15.3" rx="3.3" ry="4.3" transform="rotate(27 32.2 15.3)" fill="currentColor"/>
                        </svg>
                    </span>
                    <span class="brand-name">Paw<span>some</span></span>
                </a>
                <p>A little extra love for your pet, and a little more ease for you.</p>
            </div>
            <div class="footer-links">
                <h2>Explore</h2>
                <a href="index.php">Home</a>
                <a href="#services">Services</a>
                <a href="#about">About us</a>
            </div>
            <div class="footer-links">
                <h2>Your Pawsome</h2>
                <a href="customer/login.php">Login</a>
                <a href="customer/register.php">Register</a>
                <a href="customer/appointments.php">Book an appointment</a>
            </div>
        </div>
        <div class="container footer-bottom">
            <p>&copy; <?= htmlspecialchars($currentYear, ENT_QUOTES, 'UTF-8') ?> Pawsome. Made with care for pets and their people.</p>
            <span class="footer-paw" aria-hidden="true">♥</span>
        </div>
    </footer>
    <script>
        const serviceSearchForm = document.querySelector('#service-search-form');
        const serviceSearchInput = document.querySelector('#service-search-input');
        const serviceCategory = document.querySelector('#service-category');
        const serviceCards = document.querySelectorAll('.service-card');
        const serviceSearchEmpty = document.querySelector('#service-search-empty');

        serviceSearchForm.addEventListener('submit', (event) => {
            event.preventDefault();
            const query = serviceSearchInput.value.trim().toLowerCase();
            const category = serviceCategory.value;
            let visibleCount = 0;

            serviceCards.forEach((card) => {
                const matchesCategory = category === 'all' || card.dataset.category === category;
                const matchesQuery = card.textContent.toLowerCase().includes(query);
                card.hidden = !matchesCategory || !matchesQuery;
                visibleCount += card.hidden ? 0 : 1;
            });

            serviceSearchEmpty.hidden = visibleCount > 0;
            document.querySelector('#services').scrollIntoView({
                behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth'
            });
        });
    </script>
</body>
</html>
