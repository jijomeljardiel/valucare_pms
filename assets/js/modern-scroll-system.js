/* Scroll system: optimize at proteksyon sa scroll behavior. */


(function() {
  'use strict';
  
  class ScrollManager {
    constructor() {
      this.isScrolling = false;
      this.scrollTimeout = null;
      this.eventListeners = new Map();
      this.init();
    }
    
    init() {
      this.setupSmoothScrolling();
      this.optimizeEventListeners();
      this.setupAccessibility();
      this.handleModalScrolling();
      this.setupScrollIndicators();
      this.preventScrollJacking();
    }
    
    setupSmoothScrolling() {

      if ('scrollBehavior' in document.documentElement.style) {
        document.documentElement.style.scrollBehavior = 'smooth';
      }

      this.setupCustomSmoothScroll();
    }
    
    setupCustomSmoothScroll() {

      document.addEventListener('click', (e) => {
        const link = e.target.closest('a[href^="#"]');
        if (link) {
          e.preventDefault();
          const target = document.querySelector(link.getAttribute('href'));
          if (target) {
            this.smoothScrollTo(target, 800);
          }
        }
      });

      document.addEventListener('click', (e) => {
        const navLink = e.target.closest('[data-smooth-scroll]');
        if (navLink) {
          e.preventDefault();
          const targetId = navLink.getAttribute('href') || navLink.dataset.target;
          const target = document.querySelector(targetId);
          if (target) {
            this.smoothScrollTo(target, 600);
          }
        }
      });
    }
    
    smoothScrollTo(target, duration = 600) {
      const targetPosition = target.getBoundingClientRect().top + window.pageYOffset;
      const startPosition = window.pageYOffset;
      const distance = targetPosition - startPosition - 20; // 20px offset
      let startTime = null;
      
      const animation = (currentTime) => {
        if (startTime === null) startTime = currentTime;
        const timeElapsed = currentTime - startTime;
        const progress = Math.min(timeElapsed / duration, 1);

        const easeInOutQuad = (t) => {
          return t < 0.5 ? 2 * t * t : -1 + (4 - 2 * t) * t;
        };
        
        window.scrollTo(0, startPosition + (distance * easeInOutQuad(progress)));
        
        if (timeElapsed < duration) {
          requestAnimationFrame(animation);
        }
      };
      
      requestAnimationFrame(animation);
    }
    
    optimizeEventListeners() {

      let scrollTimeout;
      window.addEventListener('scroll', () => {
        this.isScrolling = true;
        clearTimeout(scrollTimeout);
        
        scrollTimeout = setTimeout(() => {
          this.isScrolling = false;
          this.handleScrollEnd();
        }, 150);
        
        this.handleScroll();
      }, { passive: true });

      this.setupWheelOptimization();

      this.setupKeyboardNavigation();
    }
    
    setupWheelOptimization() {
      let wheelTimeout;
      let isWheeling = false;
      
      document.addEventListener('wheel', (e) => {

        if (e.ctrlKey || e.metaKey) return;

        const scrollContainer = e.target.closest('.scroll-container');
        if (scrollContainer && Math.abs(e.deltaX) > Math.abs(e.deltaY)) {
          e.preventDefault();
          scrollContainer.scrollLeft += e.deltaX;
          return;
        }

        clearTimeout(wheelTimeout);
        isWheeling = true;
        
        wheelTimeout = setTimeout(() => {
          isWheeling = false;
        }, 100);
        
      }, { passive: false });
    }
    
    setupKeyboardNavigation() {
      document.addEventListener('keydown', (e) => {

        const allowedKeys = [32, 33, 34, 35, 36, 38, 40];
        if (allowedKeys.includes(e.keyCode)) {

          return;
        }

        if (e.altKey && e.key === 'ArrowUp') {
          e.preventDefault();
          window.scrollTo({ top: 0, behavior: 'smooth' });
        }
        
        if (e.altKey && e.key === 'ArrowDown') {
          e.preventDefault();
          window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' });
        }
      });
    }
    
    setupAccessibility() {

      this.setupFocusManagement();

      this.setupScreenReaderSupport();

      this.setupReducedMotionSupport();
    }
    
    setupFocusManagement() {

      document.addEventListener('focusin', (e) => {
        const focusedElement = e.target;
        if (focusedElement && focusedElement.scrollIntoView) {
          focusedElement.scrollIntoView({
            behavior: 'smooth',
            block: 'nearest',
            inline: 'nearest'
          });
        }
      });
    }
    
    setupScreenReaderSupport() {

      const liveRegion = document.createElement('div');
      liveRegion.setAttribute('aria-live', 'polite');
      liveRegion.setAttribute('aria-atomic', 'true');
      liveRegion.style.position = 'absolute';
      liveRegion.style.left = '-10000px';
      liveRegion.style.width = '1px';
      liveRegion.style.height = '1px';
      liveRegion.style.overflow = 'hidden';
      document.body.appendChild(liveRegion);
      
      this.liveRegion = liveRegion;
    }
    
    setupReducedMotionSupport() {
      const mediaQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
      
      const handleReducedMotion = (e) => {
        if (e.matches) {
          document.documentElement.style.scrollBehavior = 'auto';
        } else {
          document.documentElement.style.scrollBehavior = 'smooth';
        }
      };
      
      mediaQuery.addEventListener('change', handleReducedMotion);
      handleReducedMotion(mediaQuery);
    }
    
    handleModalScrolling() {

      document.addEventListener('shown.bs.modal', () => {
        document.body.style.overflow = 'hidden';
        document.body.style.paddingRight = this.getScrollbarWidth() + 'px';
      });
      
      document.addEventListener('hidden.bs.modal', () => {
        document.body.style.overflow = '';
        document.body.style.paddingRight = '';
      });

      document.addEventListener('modalOpened', () => {
        document.body.style.overflow = 'hidden';
      });
      
      document.addEventListener('modalClosed', () => {
        document.body.style.overflow = '';
      });
    }
    
    setupScrollIndicators() {

      const progressBar = document.createElement('div');
      progressBar.className = 'scroll-progress';
      progressBar.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        width: 0%;
        height: 3px;
        background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
        z-index: 9999;
        transition: width 0.1s ease;
        pointer-events: none;
      `;
      document.body.appendChild(progressBar);
      
      this.progressBar = progressBar;
    }
    
    handleScroll() {

      if (this.progressBar) {
        const scrolled = (window.pageYOffset / (document.body.scrollHeight - window.innerHeight)) * 100;
        this.progressBar.style.width = scrolled + '%';
      }

      this.detectScrollDirection();
    }
    
    handleScrollEnd() {

      this.dispatchCustomEvent('scrollEnd');
    }
    
    detectScrollDirection() {
      const currentScrollY = window.pageYOffset;
      if (currentScrollY > this.lastScrollY) {
        this.scrollDirection = 'down';
      } else if (currentScrollY < this.lastScrollY) {
        this.scrollDirection = 'up';
      }
      this.lastScrollY = currentScrollY;

      document.body.classList.toggle('scrolling-down', this.scrollDirection === 'down');
      document.body.classList.toggle('scrolling-up', this.scrollDirection === 'up');
    }
    
    preventScrollJacking() {

      const observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
          if (mutation.type === 'attributes') {
            const target = mutation.target;

            if ((target === document.body || target === document.documentElement) &&
                target.style.overflow === 'hidden') {
              target.style.overflow = '';
              console.warn('Prevented scroll blocking on', target.tagName);
            }

            if (target.style.overflowY === 'hidden' || target.style.overflowX === 'hidden') {
              target.style.overflowY = '';
              target.style.overflowX = '';
            }
          }
        });
      });
      
      observer.observe(document.body, {
        attributes: true,
        attributeFilter: ['style']
      });
      
      observer.observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['style']
      });
    }
    
    getScrollbarWidth() {
      const outer = document.createElement('div');
      outer.style.visibility = 'hidden';
      outer.style.overflow = 'scroll';
      outer.style.msOverflowStyle = 'scrollbar';
      document.body.appendChild(outer);
      
      const inner = document.createElement('div');
      outer.appendChild(inner);
      
      const scrollbarWidth = outer.offsetWidth - inner.offsetWidth;
      outer.parentNode.removeChild(outer);
      
      return scrollbarWidth;
    }
    
    dispatchCustomEvent(eventName, detail = {}) {
      const event = new CustomEvent(eventName, {
        detail,
        bubbles: true,
        cancelable: true
      });
      document.dispatchEvent(event);
    }

    scrollToTop(duration = 600) {
      this.smoothScrollTo(document.body, duration);
    }
    
    scrollToElement(selector, duration = 600) {
      const element = document.querySelector(selector);
      if (element) {
        this.smoothScrollTo(element, duration);
      }
    }
    
    getScrollPosition() {
      return {
        x: window.pageXOffset,
        y: window.pageYOffset,
        direction: this.scrollDirection
      };
    }
  }

  const scrollManager = new ScrollManager();

  window.scrollManager = scrollManager;

  document.addEventListener('DOMContentLoaded', () => {

    const scrollTopButtons = document.querySelectorAll('[data-scroll-top]');
    scrollTopButtons.forEach(button => {
      button.addEventListener('click', (e) => {
        e.preventDefault();
        scrollManager.scrollToTop();
      });
    });

    const smoothScrollTriggers = document.querySelectorAll('[data-smooth-scroll]');
    smoothScrollTriggers.forEach(trigger => {
      trigger.addEventListener('click', (e) => {
        e.preventDefault();
        const target = trigger.getAttribute('href') || trigger.dataset.target;
        if (target) {
          scrollManager.scrollToElement(target);
        }
      });
    });
  });

  if (typeof module !== 'undefined' && module.exports) {
    module.exports = ScrollManager;
  }
  
})();