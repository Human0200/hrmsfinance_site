/**
 * Интеграция форм сайта с Bitrix24 CRM
 * 
 * Подключение: добавить в конец <body>:
 * <script src="bitrix24-integration.js"></script>
 */

(function() {
  'use strict';

  // Конфигурация
  const CONFIG = {
    BITRIX24_HANDLER: '/bitrix24_handler.php', // URL вашего PHP-обработчика
    YANDEX_METRIKA_ID: 103743642
  };

  // Утилиты
  const $ = (selector, context = document) => context.querySelector(selector);
  const $$ = (selector, context = document) => context.querySelectorAll(selector);

  const formatNumber = (num) => new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 }).format(num);
  const stripMoney = (txt) => +(String(txt || '').replace(/[^\d.-]/g, '')) || 0;

  /**
   * Получение UTM-меток из URL
   */
  function getUTMParams() {
    const params = new URLSearchParams(window.location.search);
    return {
      utm_source: params.get('utm_source') || '',
      utm_medium: params.get('utm_medium') || '',
      utm_campaign: params.get('utm_campaign') || '',
      utm_content: params.get('utm_content') || '',
      utm_term: params.get('utm_term') || '',
      page_url: window.location.href
    };
  }

  /**
   * Получение данных из калькулятора
   */
  function getCalculatorData() {
    const amountEl = $('#loan-amount');
    const termEl = $('#loan-term');
    const rateEl = $('#interest-rate');
    const paymentType = $('input[name="payment-type"]:checked')?.value || 'annuity';
    const monthlyEl = $('#monthly-payment');
    const totalEl = $('#total-payment');
    const overEl = $('#overpayment');

    return {
      loan_amount: parseFloat(amountEl?.value || 0),
      loan_term: parseFloat(termEl?.value || 0),
      interest_rate: parseFloat(rateEl?.value || 0),
      payment_type: paymentType,
      monthly_payment: stripMoney(monthlyEl?.textContent),
      total_payment: stripMoney(totalEl?.textContent),
      overpayment: stripMoney(overEl?.textContent)
    };
  }

  /**
   * Отправка данных в Bitrix24
   */
  async function sendToBitrix24(formData) {
    const response = await fetch(CONFIG.BITRIX24_HANDLER, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(formData)
    });

    if (!response.ok) {
      throw new Error(`HTTP error! status: ${response.status}`);
    }

    const result = await response.json();

    if (!result.success) {
      throw new Error(result.error || 'Ошибка отправки данных');
    }

    return result;
  }

  /**
   * Отправка цели в Яндекс.Метрику
   */
  function sendYMGoal(goalName) {
    try {
      if (typeof ym !== 'undefined') {
        ym(CONFIG.YANDEX_METRIKA_ID, 'reachGoal', goalName);
        console.log('YM Goal sent:', goalName);
      }
    } catch (e) {
      console.warn('Ошибка отправки цели в YM:', e);
    }
  }

  /**
   * Показ уведомления
   */
  function showNotification(message, type = 'success') {
    // Можно заменить на более красивое уведомление (toast)
    alert(message);
  }

  /**
   * Обработчик формы обратного звонка
   */
  function initCallbackForm() {
    const form = $('#contact-form, #callback-form');
    if (!form) {
      console.warn('Callback form not found');
      return;
    }
    
    console.log('Callback form found:', form.id);

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      
      console.log('Form submitted!');

      const submitBtn = form.querySelector('button[type="submit"]');
      if (!submitBtn) {
        console.error('Submit button not found!');
        return;
      }
      
      const originalText = submitBtn.textContent;

      try {
        // Собираем данные формы
        const formData = new FormData(form);
        const data = {
          name: formData.get('name')?.trim() || '',
          phone: formData.get('phone')?.trim() || '',
          lead_source: 'callback_form',
          ...getUTMParams()
        };
        
        console.log('Form data collected:', data);

        if (!data.phone) {
          showNotification('Пожалуйста, укажите телефон', 'error');
          return;
        }

        // Блокируем кнопку
        if (submitBtn) {
          submitBtn.disabled = true;
          submitBtn.textContent = 'Отправка...';
        }
        
        console.log('Sending to:', CONFIG.BITRIX24_HANDLER);

        // Отправляем в Bitrix24
        const result = await sendToBitrix24(data);
        
        console.log('Server response:', result);

        // Успех
        showNotification('Спасибо! Ваша заявка принята. Мы скоро свяжемся с вами.');
        form.reset();
        sendYMGoal('lead_callback');

        console.log('Callback form submitted successfully:', result);

      } catch (error) {
        console.error('Error submitting callback form:', error);
        showNotification('Не удалось отправить заявку. Попробуйте позже или свяжитесь с нами по телефону.', 'error');
      } finally {
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.textContent = originalText;
        }
      }
    });
  }

  /**
   * Обработчик модальной формы с калькулятором
   */
  function initCalculatorModal() {
    const modal = $('#loan-modal');
    const form = $('#loan-modal-form');
    const applyBtn = $('#btn-apply-loan');
    const closeBtn = $('#loan-modal-close');

    if (!modal || !form) return;

    // Заполнение данных калькулятора в модальном окне
    function fillModalData() {
      // Пересчитываем калькулятор
      if (typeof calculateLoan === 'function') {
        try {
          calculateLoan();
        } catch (e) {
          console.warn('Error recalculating loan:', e);
        }
      }

      const calcData = getCalculatorData();

      // Заполняем UI поля
      $('#ui-loan-amount').value = formatNumber(calcData.loan_amount) + ' ₽';
      $('#ui-loan-term').value = calcData.loan_term;
      $('#ui-interest-rate').value = calcData.interest_rate;
      $('#ui-payment-type').value = calcData.payment_type === 'annuity' ? 'Аннуитетный' : 'Дифференцированный';
      $('#ui-monthly-payment').value = formatNumber(calcData.monthly_payment) + ' ₽';
      $('#ui-total-payment').value = formatNumber(calcData.total_payment) + ' ₽';
      $('#ui-overpayment').value = formatNumber(calcData.overpayment) + ' ₽';

      // Заполняем скрытые поля
      $('#hid-loan-amount').value = calcData.loan_amount;
      $('#hid-loan-term').value = calcData.loan_term;
      $('#hid-interest-rate').value = calcData.interest_rate;
      $('#hid-payment-type').value = calcData.payment_type;
      $('#hid-monthly-payment').value = calcData.monthly_payment;
      $('#hid-total-payment').value = calcData.total_payment;
      $('#hid-overpayment').value = calcData.overpayment;

      // UTM метки
      const utm = getUTMParams();
      $('#hid-page-url').value = utm.page_url;
      $('#hid-utm-source').value = utm.utm_source;
      $('#hid-utm-medium').value = utm.utm_medium;
      $('#hid-utm-campaign').value = utm.utm_campaign;
      $('#hid-utm-content').value = utm.utm_content;
      $('#hid-utm-term').value = utm.utm_term;
    }

    // Открытие модального окна
    function openModal() {
      fillModalData();
      modal.classList.remove('hidden');
      document.body.style.overflow = 'hidden';
      $('#lead-name')?.focus();
    }

    // Закрытие модального окна
    function closeModal() {
      modal.classList.add('hidden');
      document.body.style.overflow = '';
    }

    // События
    applyBtn?.addEventListener('click', (e) => {
      e.preventDefault();
      openModal();
    });

    closeBtn?.addEventListener('click', closeModal);

    modal?.addEventListener('click', (e) => {
      if (e.target === modal) closeModal();
    });

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && !modal.classList.contains('hidden')) {
        closeModal();
      }
    });

    // Обработка отправки формы
    form.addEventListener('submit', async (e) => {
      e.preventDefault();

      const submitBtn = form.querySelector('button[type="submit"]');
      if (!submitBtn) {
        console.error('Submit button not found in modal!');
        return;
      }
      
      const originalText = submitBtn.textContent;

      try {
        fillModalData(); // Обновляем данные перед отправкой

        // Собираем данные
        const formData = new FormData(form);
        const data = {};
        
        formData.forEach((value, key) => {
          data[key] = value;
        });

        if (!data.name || !data.phone) {
          showNotification('Пожалуйста, заполните имя и телефон', 'error');
          return;
        }

        // Блокируем кнопку
        submitBtn.disabled = true;
        submitBtn.textContent = 'Отправка...';

        // Отправляем в Bitrix24
        const result = await sendToBitrix24(data);

        // Успех
        showNotification('Спасибо! Ваша заявка принята. Наш менеджер свяжется с вами в ближайшее время.');
        form.reset();
        closeModal();
        sendYMGoal('lead_calculator_modal');

        console.log('Calculator form submitted:', result);

      } catch (error) {
        console.error('Error submitting calculator form:', error);
        showNotification('Не удалось отправить заявку. Попробуйте позже или свяжитесь с нами по телефону.', 'error');
      } finally {
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.textContent = originalText;
        }
      }
    });
  }

  /**
   * Инициализация при загрузке страницы
   */
  function init() {
    console.log('Bitrix24 integration initialized');
    
    initCallbackForm();
    initCalculatorModal();

    // Обработка кнопок "Получить кредит" и "Заказать звонок"
    $('#btn-credit')?.addEventListener('click', () => {
      $('#contact-form')?.scrollIntoView({ behavior: 'smooth' });
    });

    $('#btn-callback')?.addEventListener('click', () => {
      $('#contact-form')?.scrollIntoView({ behavior: 'smooth' });
    });
  }

  // Запуск при загрузке DOM
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();