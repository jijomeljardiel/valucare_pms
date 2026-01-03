/* Scroll protection: prevents unwanted scroll jumps. */


(function() {
  'use strict';

  function restoreScrolling() {
    document.body.style.overflow = '';
    document.body.style.overflowY = '';
    document.body.style.height = '';
    document.documentElement.style.overflow = '';
    document.documentElement.style.overflowY = '';
    document.documentElement.style.height = '';

    document.body.classList.remove('no-scroll', 'scroll-lock', 'modal-open');
    document.documentElement.classList.remove('no-scroll', 'scroll-lock');
  }

  function monitorScrollBlocking() {

    const originalStyleSetter = Object.getOwnPropertyDescriptor(HTMLElement.prototype, 'style').set;
    
    Object.defineProperty(document.body.style, 'overflow', {
      set: function(value) {
        if (value === 'hidden' || value === 'hidden !important') {
          console.warn('Blocked attempt to hide body overflow');
          return;
        }
        return originalStyleSetter.call(this, value);
      },
      get: function() {
        return originalStyleSetter.get.call(this);
      }
    });

    const observer = new MutationObserver(function(mutations) {
      mutations.forEach(function(mutation) {
        if (mutation.type === 'attributes' && mutation.attributeName === 'class') {
          const target = mutation.target;
          if (target === document.body || target === document.documentElement) {
            if (target.classList.contains('no-scroll') || 
                target.classList.contains('scroll-lock') || 
                target.classList.contains('modal-open')) {

              if (target.classList.contains('modal-open') && !document.querySelector('.modal.show')) {
                target.classList.remove('modal-open');
              }
            }
          }
        }

        if (mutation.type === 'attributes' && mutation.attributeName === 'style') {
          const target = mutation.target;
          if (target === document.body || target === document.documentElement) {
            if (target.style.overflow === 'hidden' || target.style.overflowY === 'hidden') {
              target.style.overflow = '';
              target.style.overflowY = '';
            }
          }
        }
      });
    });
    
    observer.observe(document.body, {
      attributes: true,
      attributeFilter: ['class', 'style']
    });
    
    observer.observe(document.documentElement, {
      attributes: true,
      attributeFilter: ['class', 'style']
    });
  }

  function handleModalEvents() {

    document.addEventListener('hidden.bs.modal', function() {
      setTimeout(restoreScrolling, 100);
    });

    document.addEventListener('modalClosed', function() {
      setTimeout(restoreScrolling, 100);
    });
  }

  function preventWheelBlocking() {
    document.addEventListener('wheel', function(e) {

      return true;
    }, { passive: true });
  }

  function preventKeyboardBlocking() {
    document.addEventListener('keydown', function(e) {
      const allowedKeys = [32, 33, 34, 35, 36, 38, 40]; // Space, Page Up/Down, Home, End, Arrow Up/Down
      if (allowedKeys.includes(e.keyCode)) {

        return true;
      }
    });
  }

  function init() {

    restoreScrolling();

    monitorScrollBlocking();
    handleModalEvents();
    preventWheelBlocking();
    preventKeyboardBlocking();

    setInterval(restoreScrolling, 5000);
    
    console.log('Scrolling protection initialized');
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  window.restoreScrolling = restoreScrolling;
  
})();