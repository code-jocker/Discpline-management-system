(function () {
    'use strict';

    class PlexusEngine {
        constructor(canvas, userConfig) {
            this.canvas = canvas;
            this.context = canvas.getContext('2d', { alpha: true });
            this.config = this.mergeConfig(userConfig || {});
            this.pointer = {
                x: 0,
                y: 0,
                active: false,
                radius: this.config.interactionRadius,
                lastMoveAt: 0
            };
            this.particles = [];
            this.width = 0;
            this.height = 0;
            this.dpr = Math.min(window.devicePixelRatio || 1, 2);
            this.animationFrame = 0;
            this.resizeFrame = 0;
            this.lastTimestamp = 0;
            this.connectionDistanceSq = 0;
            this.lowMotionMode = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            this.visibilityState = !document.hidden;
            this.onPointerMove = this.onPointerMove.bind(this);
            this.onPointerLeave = this.onPointerLeave.bind(this);
            this.onTouchMove = this.onTouchMove.bind(this);
            this.onTouchEnd = this.onTouchEnd.bind(this);
            this.onResize = this.onResize.bind(this);
            this.onVisibilityChange = this.onVisibilityChange.bind(this);
            this.animate = this.animate.bind(this);
        }

        mergeConfig(userConfig) {
            const rootStyles = getComputedStyle(document.documentElement);
            return {
                theme: document.body && document.body.dataset.plexusTheme ? document.body.dataset.plexusTheme : 'neon',
                speed: 0.16,
                connectionDistance: 150,
                interactionRadius: 220,
                particleDensity: 0.000034,
                minParticles: 36,
                maxParticles: 120,
                lineWidth: 1.15,
                idleDriftMultiplier: 0.74,
                magneticForce: 0.0018,
                repulsionForce: 0.0012,
                friction: 0.985,
                layers: [
                    { depth: 0.35, radius: 1.2, alpha: 0.36, speed: 0.4 },
                    { depth: 0.62, radius: 1.8, alpha: 0.54, speed: 0.72 },
                    { depth: 1, radius: 2.4, alpha: 0.86, speed: 1 }
                ],
                colors: {
                    particleA: rootStyles.getPropertyValue('--plexus-particle-a').trim() || '#38bdf8',
                    particleB: rootStyles.getPropertyValue('--plexus-particle-b').trim() || '#60a5fa',
                    particleC: rootStyles.getPropertyValue('--plexus-particle-c').trim() || '#818cf8',
                    lineA: rootStyles.getPropertyValue('--plexus-line-a').trim() || '56, 189, 248',
                    lineB: rootStyles.getPropertyValue('--plexus-line-b').trim() || '129, 140, 248'
                },
                ...userConfig
            };
        }

        start() {
            if (!this.context) {
                return;
            }

            this.setupCanvas();
            this.bindEvents();
            this.populateParticles();
            this.animationFrame = window.requestAnimationFrame(this.animate);
        }

        destroy() {
            window.cancelAnimationFrame(this.animationFrame);
            window.cancelAnimationFrame(this.resizeFrame);
            window.removeEventListener('mousemove', this.onPointerMove);
            window.removeEventListener('mouseleave', this.onPointerLeave);
            window.removeEventListener('touchmove', this.onTouchMove, { passive: true });
            window.removeEventListener('touchend', this.onTouchEnd);
            window.removeEventListener('resize', this.onResize);
            document.removeEventListener('visibilitychange', this.onVisibilityChange);
        }

        setupCanvas() {
            this.width = window.innerWidth;
            this.height = window.innerHeight;
            this.canvas.width = Math.floor(this.width * this.dpr);
            this.canvas.height = Math.floor(this.height * this.dpr);
            this.canvas.style.width = this.width + 'px';
            this.canvas.style.height = this.height + 'px';
            this.context.setTransform(this.dpr, 0, 0, this.dpr, 0, 0);
            this.connectionDistanceSq = this.config.connectionDistance * this.config.connectionDistance;
        }

        bindEvents() {
            window.addEventListener('mousemove', this.onPointerMove, { passive: true });
            window.addEventListener('mouseleave', this.onPointerLeave, { passive: true });
            window.addEventListener('touchmove', this.onTouchMove, { passive: true });
            window.addEventListener('touchend', this.onTouchEnd, { passive: true });
            window.addEventListener('resize', this.onResize, { passive: true });
            document.addEventListener('visibilitychange', this.onVisibilityChange, { passive: true });
        }

        onPointerMove(event) {
            this.pointer.x = event.clientX;
            this.pointer.y = event.clientY;
            this.pointer.active = true;
            this.pointer.lastMoveAt = performance.now();
        }

        onPointerLeave() {
            this.pointer.active = false;
        }

        onTouchMove(event) {
            if (!event.touches || !event.touches[0]) {
                return;
            }

            this.pointer.x = event.touches[0].clientX;
            this.pointer.y = event.touches[0].clientY;
            this.pointer.active = true;
            this.pointer.lastMoveAt = performance.now();
        }

        onTouchEnd() {
            this.pointer.active = false;
        }

        onResize() {
            window.cancelAnimationFrame(this.resizeFrame);
            this.resizeFrame = window.requestAnimationFrame(() => {
                this.setupCanvas();
                this.populateParticles();
            });
        }

        onVisibilityChange() {
            this.visibilityState = !document.hidden;
            if (this.visibilityState && !this.animationFrame) {
                this.animationFrame = window.requestAnimationFrame(this.animate);
            }
        }

        populateParticles() {
            const area = this.width * this.height;
            const targetCount = Math.max(
                this.config.minParticles,
                Math.min(this.config.maxParticles, Math.round(area * this.config.particleDensity))
            );

            this.particles = [];

            for (let index = 0; index < targetCount; index += 1) {
                const layer = this.config.layers[index % this.config.layers.length];
                this.particles.push(this.createParticle(layer, index));
            }
        }

        createParticle(layer, seedIndex) {
            const angle = (seedIndex * 137.5) * (Math.PI / 180);
            const speedBase = this.config.speed * layer.speed;
            return {
                layer,
                x: Math.random() * this.width,
                y: Math.random() * this.height,
                originX: Math.random() * this.width,
                originY: Math.random() * this.height,
                vx: Math.cos(angle) * speedBase,
                vy: Math.sin(angle) * speedBase,
                radius: layer.radius + Math.random() * 1.6,
                pulse: Math.random() * Math.PI * 2,
                tint: seedIndex % 3
            };
        }

        animate(timestamp) {
            if (!this.visibilityState) {
                this.animationFrame = 0;
                return;
            }

            const delta = this.lastTimestamp ? Math.min((timestamp - this.lastTimestamp) / 16.6667, 2) : 1;
            this.lastTimestamp = timestamp;

            this.context.clearRect(0, 0, this.width, this.height);

            this.updateParticles(delta, timestamp);
            this.drawConnections(timestamp);
            this.drawParticles(timestamp);

            this.animationFrame = window.requestAnimationFrame(this.animate);
        }

        updateParticles(delta, timestamp) {
            const pointerIsWarm = this.pointer.active || (timestamp - this.pointer.lastMoveAt) < 1600;
            const motionMultiplier = this.lowMotionMode ? 0.22 : (pointerIsWarm ? 1 : this.config.idleDriftMultiplier);

            for (let index = 0; index < this.particles.length; index += 1) {
                const particle = this.particles[index];
                const driftX = (particle.originX - particle.x) * 0.0008 * particle.layer.depth;
                const driftY = (particle.originY - particle.y) * 0.0008 * particle.layer.depth;

                particle.vx += driftX * delta;
                particle.vy += driftY * delta;

                if (pointerIsWarm) {
                    this.applyPointerField(particle, delta, timestamp);
                }

                particle.vx *= this.config.friction;
                particle.vy *= this.config.friction;
                particle.x += particle.vx * delta * motionMultiplier;
                particle.y += particle.vy * delta * motionMultiplier;

                if (particle.x < -40) particle.x = this.width + 40;
                if (particle.x > this.width + 40) particle.x = -40;
                if (particle.y < -40) particle.y = this.height + 40;
                if (particle.y > this.height + 40) particle.y = -40;
            }
        }

        applyPointerField(particle, delta, timestamp) {
            const dx = this.pointer.x - particle.x;
            const dy = this.pointer.y - particle.y;
            const distanceSq = dx * dx + dy * dy;

            if (distanceSq > this.pointer.radius * this.pointer.radius || distanceSq === 0) {
                return;
            }

            const distance = Math.sqrt(distanceSq);
            const forceRatio = 1 - (distance / this.pointer.radius);
            const normalizedX = dx / distance;
            const normalizedY = dy / distance;
            const wave = 0.5 + (Math.sin((timestamp * 0.0012) + particle.layer.depth) * 0.5);
            const magneticForce = this.config.magneticForce * forceRatio * particle.layer.depth;
            const repulsionForce = this.config.repulsionForce * forceRatio * wave;

            particle.vx += normalizedX * magneticForce * delta;
            particle.vy += normalizedY * magneticForce * delta;
            particle.vx -= normalizedX * repulsionForce * delta * 0.55;
            particle.vy -= normalizedY * repulsionForce * delta * 0.55;
        }

        drawConnections(timestamp) {
            const context = this.context;

            for (let index = 0; index < this.particles.length; index += 1) {
                const particleA = this.particles[index];

                for (let inner = index + 1; inner < this.particles.length; inner += 1) {
                    const particleB = this.particles[inner];
                    const dx = particleA.x - particleB.x;
                    const dy = particleA.y - particleB.y;
                    const distanceSq = dx * dx + dy * dy;

                    if (distanceSq > this.connectionDistanceSq) {
                        continue;
                    }

                    const distance = Math.sqrt(distanceSq);
                    const opacity = (1 - (distance / this.config.connectionDistance)) * 0.45;
                    const pointerBoost = this.connectionPointerBoost(particleA, particleB, timestamp);
                    const lineOpacity = Math.min(0.9, opacity + pointerBoost);

                    const gradient = context.createLinearGradient(particleA.x, particleA.y, particleB.x, particleB.y);
                    gradient.addColorStop(0, `rgba(${this.config.colors.lineA}, ${lineOpacity})`);
                    gradient.addColorStop(1, `rgba(${this.config.colors.lineB}, ${lineOpacity * 0.82})`);

                    context.beginPath();
                    context.strokeStyle = gradient;
                    context.lineWidth = this.config.lineWidth * ((particleA.layer.depth + particleB.layer.depth) * 0.5);
                    context.moveTo(particleA.x, particleA.y);
                    context.lineTo(particleB.x, particleB.y);
                    context.stroke();
                }
            }
        }

        connectionPointerBoost(particleA, particleB, timestamp) {
            if (!this.pointer.active && (timestamp - this.pointer.lastMoveAt) > 1600) {
                return 0;
            }

            const midX = (particleA.x + particleB.x) * 0.5;
            const midY = (particleA.y + particleB.y) * 0.5;
            const dx = this.pointer.x - midX;
            const dy = this.pointer.y - midY;
            const distanceSq = dx * dx + dy * dy;
            const pointerDistanceSq = this.pointer.radius * this.pointer.radius;

            if (distanceSq >= pointerDistanceSq) {
                return 0;
            }

            const ratio = 1 - (distanceSq / pointerDistanceSq);
            return ratio * 0.32;
        }

        drawParticles(timestamp) {
            const colors = [
                this.config.colors.particleA,
                this.config.colors.particleB,
                this.config.colors.particleC
            ];

            for (let index = 0; index < this.particles.length; index += 1) {
                const particle = this.particles[index];
                const pulse = 0.84 + (Math.sin(timestamp * 0.001 + particle.pulse) * 0.16);
                const alpha = particle.layer.alpha * pulse;
                const color = colors[particle.tint];

                this.context.beginPath();
                this.context.fillStyle = this.hexToRgba(color, alpha);
                this.context.shadowBlur = 12 * particle.layer.depth;
                this.context.shadowColor = this.hexToRgba(color, alpha * 0.85);
                this.context.arc(particle.x, particle.y, particle.radius, 0, Math.PI * 2);
                this.context.fill();
            }

            this.context.shadowBlur = 0;
        }

        hexToRgba(hex, alpha) {
            const clean = hex.replace('#', '');
            const normalized = clean.length === 3
                ? clean.split('').map((value) => value + value).join('')
                : clean;
            const bigint = parseInt(normalized, 16);
            const red = (bigint >> 16) & 255;
            const green = (bigint >> 8) & 255;
            const blue = bigint & 255;
            return `rgba(${red}, ${green}, ${blue}, ${alpha})`;
        }
    }

    function bootPlexus() {
        const canvas = document.querySelector('[data-plexus-canvas]');
        if (!canvas) {
            return;
        }

        const config = window.SBMSPlexusConfig || {};
        const engine = new PlexusEngine(canvas, config);
        engine.start();
        window.SBMSPlexusEngine = engine;
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootPlexus, { passive: true });
    } else {
        bootPlexus();
    }
}());
