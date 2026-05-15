/**
 * ZenCoupon AI Assistant Admin JavaScript
 */

(function() {
    'use strict';

    // Initialize on document ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initZenCoupon);
    } else {
        initZenCoupon();
    }

    function initZenCoupon() {
        // Tab navigation
        initTabs();
        
        // Buttons
        initButtons();
        
        // Form interactions
        initFormInteractions();
        
        // Dynamic content
        initDynamicContent();
        
        // Scroll handling
        initScrollFix();
    }

    /**
     * Initialize tab navigation
     */
    function initTabs() {
        const tabButtons = document.querySelectorAll('[data-zencoupon-target]');
        
        tabButtons.forEach(button => {
            button.addEventListener('click', function(e) {
                e.preventDefault();
                
                const targetId = this.getAttribute('data-zencoupon-target');
                const targetPane = document.querySelector(targetId);
                
                if (!targetPane) return;
                
                // Hide all panes
                document.querySelectorAll('.tab-pane').forEach(pane => {
                    pane.classList.remove('active');
                });
                
                // Deactivate all buttons
                document.querySelectorAll('.nav-link').forEach(btn => {
                    btn.classList.remove('active');
                    btn.setAttribute('aria-selected', 'false');
                });
                
                // Show selected pane
                targetPane.classList.add('active');
                
                // Activate button
                this.classList.add('active');
                this.setAttribute('aria-selected', 'true');
                
                // Scroll tab into view if needed
                this.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'nearest' });
            });
        });
    }

    /**
     * Initialize button interactions
     */
    function initButtons() {
        // Send Command button
        const runButton = document.getElementById('zencoupon-run-button');
        if (runButton) {
            runButton.addEventListener('click', executeCommand);
        }
        
        // Reset button
        const resetButton = document.getElementById('zencoupon-reset-button');
        if (resetButton) {
            resetButton.addEventListener('click', resetForm);
        }
        
        // Suggested prompts
        document.querySelectorAll('.zencoupon-suggested-prompt').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                const prompt = this.getAttribute('data-prompt');
                document.getElementById('zencoupon-command').value = prompt;
            });
        });
        
        // API key toggle buttons
        const toggleApiKey = document.getElementById('zencoupon-toggle-api-key');
        if (toggleApiKey) {
            toggleApiKey.addEventListener('click', function(e) {
                e.preventDefault();
                togglePasswordField('groq_api_key');
            });
        }
        
        const toggleGeminiKey = document.getElementById('zencoupon-toggle-gemini-api-key');
        if (toggleGeminiKey) {
            toggleGeminiKey.addEventListener('click', function(e) {
                e.preventDefault();
                togglePasswordField('gemini_api_key');
            });
        }
        
        // Delete coupon buttons
        document.querySelectorAll('.zencoupon-delete-coupon-button').forEach(btn => {
            btn.addEventListener('click', deleteCoupon);
        });
        
        // Close alert buttons
        document.querySelectorAll('.zencoupon-close-button').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                this.closest('.alert').remove();
            });
        });
    }

    /**
     * Initialize form interactions
     */
    function initFormInteractions() {
        // AI Provider selection changes
        const aiProviderSelect = document.getElementById('ai_provider');
        if (aiProviderSelect) {
            aiProviderSelect.addEventListener('change', toggleProviderSettings);
            // Trigger on load
            toggleProviderSettings();
        }
    }

    /**
     * Toggle between Groq and Gemini settings
     */
    function toggleProviderSettings() {
        const provider = document.getElementById('ai_provider')?.value || 'groq';
        const groqSettings = document.getElementById('groq-settings');
        const geminiSettings = document.getElementById('gemini-settings');
        
        if (groqSettings) {
            groqSettings.style.display = provider === 'groq' ? 'block' : 'none';
        }
        if (geminiSettings) {
            geminiSettings.style.display = provider === 'gemini' ? 'block' : 'none';
        }
    }

    /**
     * Toggle password field visibility
     */
    function togglePasswordField(fieldName) {
        const field = document.querySelector(`input[name*="${fieldName}"]`);
        if (!field) return;
        
        const isPassword = field.type === 'password';
        field.type = isPassword ? 'text' : 'password';
        
        // Update button text
        const button = event.target;
        if (button) {
            button.textContent = isPassword ? 'Hide' : 'Show';
        }
    }

    /**
     * Execute AI command
     */
    async function executeCommand(e) {
        e.preventDefault();
        
        const command = document.getElementById('zencoupon-command')?.value;
        if (!command || !command.trim()) {
            showAlert('Please enter a command.', 'danger');
            return;
        }
        
        const button = document.getElementById('zencoupon-run-button');
        const statusDiv = document.getElementById('zencoupon-status');
        const resultDiv = document.getElementById('zencoupon-result');
        
        if (!button || !statusDiv) return;
        
        try {
            // Show loading state
            button.disabled = true;
            statusDiv.textContent = 'Processing...';
            resultDiv.innerHTML = '';
            
            // Gather form data
            const formData = new FormData(document.getElementById('zencoupon-command-form'));
            const restrictions = buildRestrictions(formData);
            
            const data = new FormData();
            data.append('action', 'zencoupon_execute_command');
            data.append('nonce', ZenCouponAI.nonce);
            data.append('command', command);
            data.append('restrictions', JSON.stringify(restrictions));
            
            const response = await fetch(ZenCouponAI.ajax_url, {
                method: 'POST',
                body: data
            });
            
            const result = await response.json();
            
            if (result.success) {
                showAlert('Command executed successfully!', 'success');
                statusDiv.textContent = '';
                resultDiv.innerHTML = formatResult(result.data);
                
                // Refresh generated coupons
                refreshGeneratedCoupons();
                refreshDashboardStats();
            } else {
                showAlert(result.data?.message || 'Error executing command', 'danger');
                statusDiv.textContent = '';
            }
        } catch (error) {
            showAlert('Error: ' + error.message, 'danger');
            statusDiv.textContent = '';
        } finally {
            button.disabled = false;
        }
    }

    /**
     * Build restrictions object from form
     */
    function buildRestrictions(formData) {
        const restrictions = {};
        
        const minAmount = formData.get('minimum_amount');
        if (minAmount) restrictions.minimum_amount = parseFloat(minAmount);
        
        const maxAmount = formData.get('maximum_amount');
        if (maxAmount) restrictions.maximum_amount = parseFloat(maxAmount);
        
        const usageLimit = formData.get('usage_limit');
        if (usageLimit) restrictions.usage_limit = parseInt(usageLimit);
        
        const usageLimitPerUser = formData.get('usage_limit_per_user');
        if (usageLimitPerUser) restrictions.usage_limit_per_user = parseInt(usageLimitPerUser);
        
        const emailRestrictions = formData.get('email_restrictions');
        if (emailRestrictions) restrictions.email_restrictions = emailRestrictions.split(',').map(e => e.trim()).filter(e => e);
        
        const expiryDate = formData.get('expiry_date');
        if (expiryDate) restrictions.expiry_date = expiryDate;
        
        const productCategories = formData.getAll('product_categories[]');
        if (productCategories.length) restrictions.product_categories = productCategories.map(Number);
        
        const excludedCategories = formData.getAll('excluded_product_categories[]');
        if (excludedCategories.length) restrictions.excluded_product_categories = excludedCategories.map(Number);
        
        if (formData.get('individual_use')) restrictions.individual_use = true;
        if (formData.get('free_shipping')) restrictions.free_shipping = true;
        if (formData.get('exclude_sale_items')) restrictions.exclude_sale_items = true;
        
        return restrictions;
    }

    /**
     * Format result output
     */
    function formatResult(data) {
        if (!data) return '';
        
        let html = '<div style="margin-top: 1rem; padding: 1rem; background: #f5f5f5; border-radius: 4px;">';
        html += '<h3 style="margin-top: 0;">Result</h3>';
        html += '<pre style="margin: 0; white-space: pre-wrap; word-wrap: break-word;">';
        html += escapeHtml(JSON.stringify(data, null, 2));
        html += '</pre>';
        html += '</div>';
        
        return html;
    }

    /**
     * Escape HTML special characters
     */
    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, m => map[m]);
    }

    /**
     * Show alert message
     */
    function showAlert(message, type = 'info') {
        const alertDiv = document.createElement('div');
        alertDiv.className = `alert alert-${type} zencoupon-alert`;
        alertDiv.setAttribute('role', 'alert');
        alertDiv.innerHTML = `
            ${escapeHtml(message)}
            <button type="button" class="zencoupon-close-button" aria-label="Close">&times;</button>
        `;
        
        // Insert at top of page
        const wrap = document.querySelector('.wrap');
        if (wrap) {
            wrap.insertBefore(alertDiv, wrap.firstChild);
        } else {
            document.body.insertBefore(alertDiv, document.body.firstChild);
        }
        
        // Add close handler
        alertDiv.querySelector('.zencoupon-close-button').addEventListener('click', function() {
            alertDiv.remove();
        });
        
        // Auto-remove after 5 seconds
        setTimeout(() => {
            if (alertDiv.parentElement) alertDiv.remove();
        }, 5000);
    }

    /**
     * Refresh generated coupons
     */
    async function refreshGeneratedCoupons() {
        try {
            const data = new FormData();
            data.append('action', 'zencoupon_refresh_generated_coupons');
            data.append('nonce', ZenCouponAI.nonce);
            
            const response = await fetch(ZenCouponAI.ajax_url, {
                method: 'POST',
                body: data
            });
            
            const result = await response.json();
            
            if (result.success && result.data?.html) {
                const wrapper = document.getElementById('zencoupon-generated-coupons-wrapper');
                if (wrapper) {
                    wrapper.innerHTML = result.data.html;
                    // Re-initialize delete buttons
                    document.querySelectorAll('.zencoupon-delete-coupon-button').forEach(btn => {
                        btn.removeEventListener('click', deleteCoupon);
                        btn.addEventListener('click', deleteCoupon);
                    });
                }
            }
        } catch (error) {
            console.error('Error refreshing coupons:', error);
        }
    }

    /**
     * Refresh dashboard stats
     */
    async function refreshDashboardStats() {
        try {
            const data = new FormData();
            data.append('action', 'zencoupon_refresh_dashboard_stats');
            data.append('nonce', ZenCouponAI.nonce);
            
            const response = await fetch(ZenCouponAI.ajax_url, {
                method: 'POST',
                body: data
            });
            
            const result = await response.json();
            
            if (result.success && result.data) {
                const stats = result.data.stats || {};
                
                if (stats.active_coupons !== undefined) {
                    const el = document.getElementById('zencoupon-active-coupons');
                    if (el) el.textContent = stats.active_coupons;
                }
                if (stats.expiring_soon !== undefined) {
                    const el = document.getElementById('zencoupon-expiring-soon');
                    if (el) el.textContent = stats.expiring_soon;
                }
                if (stats.highest_discount !== undefined) {
                    const el = document.getElementById('zencoupon-highest-discount');
                    if (el) el.textContent = stats.highest_discount;
                }
                
                if (result.data.recent_html) {
                    const activity = document.getElementById('zencoupon-recent-activity');
                    if (activity) activity.innerHTML = result.data.recent_html;
                }
            }
        } catch (error) {
            console.error('Error refreshing stats:', error);
        }
    }

    /**
     * Delete coupon
     */
    async function deleteCoupon(e) {
        e.preventDefault();
        
        const couponId = this.getAttribute('data-coupon-id');
        if (!couponId) return;
        
        if (!confirm('Are you sure you want to delete this coupon?')) {
            return;
        }
        
        try {
            const data = new FormData();
            data.append('action', 'zencoupon_delete_coupon');
            data.append('nonce', ZenCouponAI.nonce);
            data.append('coupon_id', couponId);
            
            const response = await fetch(ZenCouponAI.ajax_url, {
                method: 'POST',
                body: data
            });
            
            const result = await response.json();
            
            if (result.success) {
                showAlert('Coupon deleted successfully!', 'success');
                refreshGeneratedCoupons();
                refreshDashboardStats();
            } else {
                showAlert(result.data?.message || 'Error deleting coupon', 'danger');
            }
        } catch (error) {
            showAlert('Error: ' + error.message, 'danger');
        }
    }

    /**
     * Reset form
     */
    function resetForm(e) {
        e.preventDefault();
        
        const form = document.getElementById('zencoupon-command-form');
        if (form) {
            form.reset();
        }
        
        document.getElementById('zencoupon-status').textContent = '';
        document.getElementById('zencoupon-result').innerHTML = '';
    }

    /**
     * Initialize dynamic content (search, etc.)
     */
    function initDynamicContent() {
        // Coupon search
        const searchInput = document.getElementById('zencoupon-search-generated');
        if (searchInput) {
            searchInput.addEventListener('input', searchCoupons);
        }
    }

    /**
     * Search coupons
     */
    function searchCoupons(e) {
        const query = e.target.value.toLowerCase();
        const table = document.querySelector('.table tbody');
        
        if (!table) return;
        
        table.querySelectorAll('tr').forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(query) ? '' : 'none';
        });
    }

    /**
     * Fix vertical scrolling issues
     */
    function initScrollFix() {
        // Ensure tabs don't cause horizontal scrolling
        const tabs = document.querySelectorAll('.nav');
        tabs.forEach(tab => {
            tab.addEventListener('wheel', function(e) {
                if (e.deltaY === 0) return;
                e.preventDefault();
                this.scrollLeft += e.deltaY;
            }, { passive: false });
        });
        
        // Prevent body overflow on mobile
        if (window.innerWidth < 768) {
            document.body.style.overflow = 'hidden';
            document.body.style.width = '100vw';
        }
        
        // Listen for window resize
        window.addEventListener('resize', function() {
            if (window.innerWidth < 768) {
                document.body.style.overflow = 'hidden';
                document.body.style.width = '100vw';
            } else {
                document.body.style.overflow = '';
                document.body.style.width = '';
            }
        });
    }
})();