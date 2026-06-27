/**
 * ZenCoupon AI Assistant Admin JavaScript
 */

(function() {
    'use strict';

    let testConnectionNoticeTimer = null;

    // Initialize on document ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initZenCoupon);
    } else {
        initZenCoupon();
    }

    function initZenCoupon() {
        // Tab navigation
        initTabs();
        initDocsTabs();
        
        // Buttons
        initButtons();

        // Provider settings
        initProviderSettings();
        
        // Dynamic content
        initDynamicContent();

        // AI Campaign Builder
        initCampaignBuilder();

        // Scroll handling
        initScrollFix();
    }

    /**
     * Initialize AI Campaign Builder interactions.
     */
    // Emails the admin removed from the audience before starting the campaign.
    let campaignExcludedEmails = [];

    function initCampaignBuilder() {
        const generateBtn = document.getElementById('zencoupon-generate-campaign');
        const startBtn = document.getElementById('zencoupon-start-campaign');
        const testBtn = document.getElementById('zencoupon-send-test-email');
        const segmentSelect = document.getElementById('zencoupon-segment-type-select');
        const regenerateBtn = document.getElementById('zencoupon-regenerate-recipients');

        if (generateBtn) {
            generateBtn.addEventListener('click', generateCampaignDraft);
        }
        if (startBtn) {
            startBtn.addEventListener('click', startCampaign);
        }
        if (testBtn) {
            testBtn.addEventListener('click', sendTestEmail);
        }
        if (segmentSelect) {
            segmentSelect.addEventListener('change', function() {
                const selectEl = document.getElementById('zencoupon-segment-type');
                if (selectEl) selectEl.value = this.value;
            });
        }
        if (regenerateBtn) {
            regenerateBtn.addEventListener('click', regenerateRecipients);
        }

        document.querySelectorAll('.zencoupon-toggle-campaign').forEach(btn => {
            btn.addEventListener('click', toggleCampaign);
        });
    }

    async function generateCampaignDraft(e) {
        e.preventDefault();

        const ideaEl = document.getElementById('zencoupon-campaign-idea');
        const statusEl = document.getElementById('zencoupon-campaign-status');
        const button = document.getElementById('zencoupon-generate-campaign');
        if (!ideaEl || !button) return;

        const idea = ideaEl.value.trim();
        if (!idea) {
            if (statusEl) {
                statusEl.textContent = 'Please describe the campaign idea.';
                statusEl.className = 'small text-danger';
            }
            return;
        }

        button.disabled = true;
        if (statusEl) {
            statusEl.textContent = 'Generating draft...';
            statusEl.className = 'small text-muted';
        }

        try {
            const data = new FormData();
            data.append('action', 'zencoupon_ai_assistant_generate_campaign');
            data.append('nonce', ZenCouponAIAssistantData.nonce);
            data.append('idea', idea);

            const response = await fetch(ZenCouponAIAssistantData.ajax_url, { method: 'POST', body: data });
            const result = await response.json();

            if (!result.success) {
                if (statusEl) {
                    statusEl.textContent = result.data?.message || 'Could not generate draft.';
                    statusEl.className = 'small text-danger';
                }
                return;
            }

            populateCampaignDraft(result.data);
            if (statusEl) {
                statusEl.textContent = 'Draft ready. Review and start below.';
                statusEl.className = 'small text-success';
            }
        } catch (error) {
            if (statusEl) {
                statusEl.textContent = 'Error: ' + error.message;
                statusEl.className = 'small text-danger';
            }
        } finally {
            button.disabled = false;
        }
    }

    function populateCampaignDraft(data) {
        const draft = data.draft || {};
        const wrapper = document.getElementById('zencoupon-campaign-draft');
        if (!wrapper) return;

        setValue('zencoupon-draft-name', draft.name);
        setValue('zencoupon-draft-discount-type', draft.discount_type);
        setValue('zencoupon-draft-discount-amount', draft.discount_amount);
        setValue('zencoupon-draft-expiry', draft.expiry_days);
        setValue('zencoupon-draft-usage', draft.usage_limit);
        setValue('zencoupon-draft-usage-user', draft.usage_limit_per_user);
        setValue('zencoupon-draft-subject', draft.email_subject);
        setValue('zencoupon-draft-body', draft.email_body);
        setValue('zencoupon-draft-social', draft.social_copy);

        // Display segment detection UI
        const segmentType = data.segment_type || 'winback';
        const segmentParams = data.segment_params || {};
        const segmentLabel = data.draft?.segment_label || 'Customers';
        renderSegmentDetection(segmentType, segmentParams, segmentLabel);

        campaignExcludedEmails = [];
        renderAudiencePanel(data.recipients || [], data.total != null ? data.total : 0);

        wrapper.style.display = 'block';
        wrapper.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function renderSegmentDetection(segmentType, segmentParams, segmentLabel) {
        const card = document.getElementById('zencoupon-segment-detection');
        const typeInput = document.getElementById('zencoupon-segment-type');
        const typeSelect = document.getElementById('zencoupon-segment-type-select');
        const paramsContainer = document.getElementById('zencoupon-segment-params-inputs');
        const labelEl = document.getElementById('zencoupon-segment-label');

        if (typeInput) typeInput.value = segmentType;
        if (typeSelect) typeSelect.value = segmentType;
        if (labelEl) labelEl.textContent = segmentLabel;

        // Populate hidden params
        if (paramsContainer) {
            paramsContainer.innerHTML = '';
            Object.entries(segmentParams).forEach(([key, val]) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = `segment_params[${key}]`;
                input.value = val;
                paramsContainer.appendChild(input);
            });
        }

        if (card) card.style.display = 'block';
    }

    function renderAudiencePanel(recipients, total) {
        const card = document.getElementById('zencoupon-audience-card');
        const list = document.getElementById('zencoupon-audience-list');
        const countEl = document.getElementById('zencoupon-campaign-count');
        if (!card || !list) return;

        list.innerHTML = '';

        if (!recipients.length) {
            list.innerHTML = '<p class="small text-muted mb-0">No customers matched this segment.</p>';
        }

        recipients.forEach(recipient => {
            const row = document.createElement('div');
            row.className = 'zencoupon-audience-row';
            row.setAttribute('data-email', recipient.email);

            const info = document.createElement('div');
            info.className = 'zencoupon-audience-info';
            const name = document.createElement('span');
            name.className = 'zencoupon-audience-name';
            name.textContent = recipient.name || 'Customer';
            const email = document.createElement('span');
            email.className = 'zencoupon-audience-email';
            email.textContent = recipient.email;
            info.appendChild(name);
            info.appendChild(email);

            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'zencoupon-audience-remove';
            remove.setAttribute('aria-label', 'Remove customer');
            remove.textContent = '×';
            remove.addEventListener('click', function() {
                campaignExcludedEmails.push(recipient.email);
                row.remove();
                updateAudienceCount(-1);
            });

            row.appendChild(info);
            row.appendChild(remove);
            list.appendChild(row);
        });

        if (countEl) countEl.textContent = total;
        card.dataset.total = total;
        card.style.display = 'block';
    }

    function updateAudienceCount(delta) {
        const countEl = document.getElementById('zencoupon-campaign-count');
        const card = document.getElementById('zencoupon-audience-card');
        if (!countEl || !card) return;
        const next = Math.max(0, parseInt(card.dataset.total || '0', 10) + delta);
        card.dataset.total = next;
        countEl.textContent = next;
    }

    async function toggleCampaign(e) {
        e.preventDefault();
        const button = e.currentTarget;
        const campaignId = button.getAttribute('data-campaign-id');
        if (!campaignId) return;

        button.disabled = true;
        try {
            const data = new FormData();
            data.append('action', 'zencoupon_ai_assistant_toggle_campaign');
            data.append('nonce', ZenCouponAIAssistantData.nonce);
            data.append('campaign_id', campaignId);

            const response = await fetch(ZenCouponAIAssistantData.ajax_url, { method: 'POST', body: data });
            const result = await response.json();

            if (result.success) {
                const status = result.data.status;
                button.textContent = status === 'running' ? 'Pause' : 'Resume';
                const row = button.closest('tr');
                const badge = row ? row.querySelector('.zencoupon-campaign-status-badge') : null;
                if (badge) {
                    badge.textContent = status.charAt(0).toUpperCase() + status.slice(1);
                    badge.className = 'badge zencoupon-campaign-status-badge ' + (status === 'paused' ? 'bg-secondary-subtle text-secondary' : 'bg-primary-subtle text-primary');
                }
                showAlert(result.data.message || 'Campaign updated.', 'success');
            } else {
                showAlert(result.data?.message || 'Could not update campaign.', 'danger');
            }
        } catch (error) {
            showAlert('Error: ' + error.message, 'danger');
        } finally {
            button.disabled = false;
        }
    }

    function setValue(id, value) {
        const el = document.getElementById(id);
        if (el && value != null) el.value = value;
    }

    async function startCampaign(e) {
        e.preventDefault();

        const button = document.getElementById('zencoupon-start-campaign');
        const statusEl = document.getElementById('zencoupon-start-status');
        if (!button) return;

        if (!confirm('Start this campaign and begin sending emails to the targeted customers?')) {
            return;
        }

        button.disabled = true;
        if (statusEl) {
            statusEl.textContent = 'Starting campaign...';
            statusEl.className = 'small text-muted';
        }

        try {
            const data = new FormData();
            data.append('action', 'zencoupon_ai_assistant_start_campaign');
            data.append('nonce', ZenCouponAIAssistantData.nonce);

            const segmentTypeEl = document.getElementById('zencoupon-segment-type');
            if (segmentTypeEl) data.append('segment_type', segmentTypeEl.value);

            document.querySelectorAll('#zencoupon-segment-params-inputs input').forEach(input => {
                data.append(input.name, input.value);
            });
            campaignExcludedEmails.forEach(email => data.append('excluded_emails[]', email));
            ['name', 'discount_type', 'discount_amount', 'expiry_days', 'usage_limit', 'usage_limit_per_user', 'email_subject', 'email_body', 'social_copy'].forEach(field => {
                const el = document.querySelector(`#zencoupon-campaign-review [name="${field}"]`);
                if (el) data.append(field, el.value);
            });

            const response = await fetch(ZenCouponAIAssistantData.ajax_url, { method: 'POST', body: data });
            const result = await response.json();

            if (statusEl) {
                statusEl.textContent = result.data?.message || (result.success ? 'Campaign started.' : 'Could not start campaign.');
                statusEl.className = result.success ? 'small text-success' : 'small text-danger';
            }

            if (result.success) {
                showAlert(result.data?.message || 'Campaign started.', 'success');
                setTimeout(() => window.location.reload(), 1500);
            }
        } catch (error) {
            if (statusEl) {
                statusEl.textContent = 'Error: ' + error.message;
                statusEl.className = 'small text-danger';
            }
        } finally {
            button.disabled = false;
        }
    }

    async function sendTestEmail(e) {
        e.preventDefault();

        const button = document.getElementById('zencoupon-send-test-email');
        const emailInput = document.getElementById('zencoupon-test-email');
        const statusEl = document.getElementById('zencoupon-test-email-status');
        if (!button || !emailInput) return;

        const testEmail = emailInput.value.trim();
        if (!testEmail) {
            if (statusEl) {
                statusEl.textContent = 'Please enter an email address.';
                statusEl.className = 'text-danger';
            }
            return;
        }

        button.disabled = true;
        if (statusEl) {
            statusEl.textContent = 'Sending test email...';
            statusEl.className = 'text-muted';
        }

        try {
            const data = new FormData();
            data.append('action', 'zencoupon_ai_assistant_send_test_email');
            data.append('nonce', ZenCouponAIAssistantData.nonce);
            data.append('test_email', testEmail);
            ['email_subject', 'email_body', 'discount_type', 'discount_amount', 'expiry_days'].forEach(field => {
                const el = document.querySelector(`#zencoupon-campaign-review [name="${field}"]`);
                if (el) data.append(field, el.value);
            });

            const response = await fetch(ZenCouponAIAssistantData.ajax_url, { method: 'POST', body: data });
            const result = await response.json();

            if (statusEl) {
                statusEl.textContent = result.data?.message || (result.success ? 'Test email sent.' : 'Could not send test email.');
                statusEl.className = result.success ? 'text-success' : 'text-danger';
            }
        } catch (error) {
            if (statusEl) {
                statusEl.textContent = 'Error: ' + error.message;
                statusEl.className = 'text-danger';
            }
        } finally {
            button.disabled = false;
        }
    }

    async function regenerateRecipients(e) {
        e.preventDefault();

        const button = document.getElementById('zencoupon-regenerate-recipients');
        const statusEl = document.getElementById('zencoupon-regenerate-status');
        const segmentSelect = document.getElementById('zencoupon-segment-type-select');
        if (!button || !segmentSelect) return;

        button.disabled = true;
        if (statusEl) {
            statusEl.textContent = 'Regenerating...';
            statusEl.className = 'text-muted';
        }

        try {
            const data = new FormData();
            data.append('action', 'zencoupon_ai_assistant_regenerate_recipients');
            data.append('nonce', ZenCouponAIAssistantData.nonce);
            data.append('segment_type', segmentSelect.value);

            // Collect segment_params from hidden inputs
            document.querySelectorAll('#zencoupon-segment-params-inputs input').forEach(input => {
                data.append(input.name, input.value);
            });

            const response = await fetch(ZenCouponAIAssistantData.ajax_url, { method: 'POST', body: data });
            const result = await response.json();

            if (result.success) {
                if (statusEl) {
                    statusEl.textContent = 'Updated: ' + result.data.total + ' customers';
                    statusEl.className = 'text-success';
                }
                renderAudiencePanel(result.data.recipients || [], result.data.total || 0);
                campaignExcludedEmails = [];
            } else {
                if (statusEl) {
                    statusEl.textContent = result.data?.message || 'Could not regenerate recipients.';
                    statusEl.className = 'text-danger';
                }
            }
        } catch (error) {
            if (statusEl) {
                statusEl.textContent = 'Error: ' + error.message;
                statusEl.className = 'text-danger';
            }
        } finally {
            button.disabled = false;
        }
    }

    /**
     * Initialize Docs & Support page section navigation.
     */
    function initDocsTabs() {
        const docButtons = document.querySelectorAll('[data-zencoupon-docs-target]');
        if (!docButtons.length) return;

        docButtons.forEach(button => {
            button.addEventListener('click', function(e) {
                e.preventDefault();

                const targetId = this.getAttribute('data-zencoupon-docs-target');
                const targetPane = document.querySelector(targetId);
                if (!targetPane) return;

                document.querySelectorAll('.zencoupon-docs-section').forEach(section => {
                    section.classList.remove('active');
                });

                document.querySelectorAll('[data-zencoupon-docs-target]').forEach(navItem => {
                    navItem.classList.remove('active');
                    navItem.setAttribute('aria-selected', 'false');
                });

                targetPane.classList.add('active');
                this.classList.add('active');
                this.setAttribute('aria-selected', 'true');
            });
        });
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
                
                // Deactivate all tab buttons
                document.querySelectorAll('.nav-link, .zencoupon-automation-nav-item:not(.disabled)').forEach(btn => {
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

        const polishButton = document.getElementById('zencoupon-polish-button');
        if (polishButton) {
            polishButton.addEventListener('click', polishPrompt);
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
                togglePasswordField('groq_api_key', e.currentTarget);
            });
        }

        document.querySelectorAll('.zencoupon-toggle-api-key').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                togglePasswordField(this.getAttribute('data-field'), this);
            });
        });

        const testConnection = document.getElementById('zencoupon-test-connection');
        if (testConnection) {
            testConnection.addEventListener('click', testProviderConnection);
        }

        const supportForm = document.getElementById('zencoupon-support-form');
        if (supportForm) {
            supportForm.addEventListener('submit', sendSupportRequest);
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
     * Toggle password field visibility
     */
    function initProviderSettings() {
        const providerSelect = document.getElementById('zencoupon-ai-provider');
        if (providerSelect) {
            providerSelect.addEventListener('change', updateProviderSettingsVisibility);
            updateProviderSettingsVisibility();
        }

        document.querySelectorAll('.zencoupon-model-select').forEach(select => {
            select.addEventListener('change', syncModelInputFromSelect);
            syncModelInputFromSelect.call(select);
        });
    }

    function updateProviderSettingsVisibility() {
        const provider = document.getElementById('zencoupon-ai-provider')?.value || 'groq';

        document.querySelectorAll('.zencoupon-provider-settings').forEach(panel => {
            panel.style.display = panel.getAttribute('data-provider') === provider ? 'block' : 'none';
        });
    }

    function syncModelInputFromSelect() {
        const inputId = this.getAttribute('data-input');
        const input = document.getElementById(inputId);
        if (!input) return;

        const isCustom = this.value === '__custom__';
        input.style.display = isCustom ? 'block' : 'none';

        if (!isCustom) {
            input.value = this.value;
        }
    }

    function togglePasswordField(fieldName, button) {
        const field = document.querySelector(`input[name*="${fieldName}"]`);
        if (!field) return;
        
        const isPassword = field.type === 'password';
        field.type = isPassword ? 'text' : 'password';
        
        if (button) {
            button.textContent = isPassword ? 'Hide' : 'Show';
        }
    }

    async function testProviderConnection(e) {
        e.preventDefault();

        const button = document.getElementById('zencoupon-test-connection');
        const resultEl = document.getElementById('zencoupon-test-connection-result');
        if (!button || !resultEl) return;

        clearTestConnectionNoticeTimer();
        button.disabled = true;
        resultEl.textContent = 'Testing...';
        resultEl.className = 'small text-muted';

        try {
            const settingsForm = document.querySelector('form[action="options.php"]');
            const data = settingsForm ? new FormData(settingsForm) : new FormData();
            data.set('action', 'zencoupon_ai_assistant_test_connection');
            data.set('nonce', ZenCouponAIAssistantData.nonce);

            const response = await fetch(ZenCouponAIAssistantData.ajax_url, {
                method: 'POST',
                body: data
            });
            const result = await response.json();

            resultEl.textContent = result.success
                ? (result.data?.message || 'Connection successful.')
                : (result.data?.message || 'Connection failed.');
            resultEl.className = result.success ? 'small text-success' : 'small text-danger';
            scheduleTestConnectionNoticeClear(resultEl);
        } catch (error) {
            resultEl.textContent = 'Error: ' + error.message;
            resultEl.className = 'small text-danger';
            scheduleTestConnectionNoticeClear(resultEl);
        } finally {
            button.disabled = false;
        }
    }

    function clearTestConnectionNoticeTimer() {
        if (testConnectionNoticeTimer) {
            clearTimeout(testConnectionNoticeTimer);
            testConnectionNoticeTimer = null;
        }
    }

    function scheduleTestConnectionNoticeClear(resultEl) {
        clearTestConnectionNoticeTimer();
        testConnectionNoticeTimer = setTimeout(() => {
            resultEl.textContent = '';
            resultEl.className = 'small text-muted';
            testConnectionNoticeTimer = null;
        }, 5000);
    }

    function polishPrompt(e) {
        e.preventDefault();

        const commandEl = document.getElementById('zencoupon-command');
        const form = document.getElementById('zencoupon-command-form');
        if (!commandEl || !form) return;

        const formData = new FormData(form);
        const original = commandEl.value.trim();
        const lower = original.toLowerCase();

        let code = '';
        let amount = '';
        let type = 'percent';

        const amountMatch = original.match(/(\d+(?:\.\d+)?)\s*(%|percent|fixed|tk|\$|usd)?/i);
        if (amountMatch) {
            amount = amountMatch[1];
            if (amountMatch[2] && !['%', 'percent'].includes(amountMatch[2].toLowerCase())) {
                type = 'fixed cart';
            }
        }

        const codeMatch = original.match(/\b([A-Z0-9]{4,20})\b/);
        if (codeMatch) {
            code = codeMatch[1].toUpperCase();
        } else if (lower.includes('blackfriday')) {
            code = 'BLACKFRIDAY';
        } else if (lower.includes('summer')) {
            code = 'SUMMER';
        }

        const isUpdateIntent = /\b(edit|update|change|modify|revise|adjust|existing|recent|latest|last)\b/i.test(original);
        const recentIntent = /\b(recent|latest|last)\b/i.test(original);
        const recentCoupon = getRecentGeneratedCoupon();

        let polished = '';
        if (isUpdateIntent) {
            if (recentIntent && recentCoupon) {
                polished = `Update coupon ID ${recentCoupon.id}`;
                if (recentCoupon.code) {
                    polished += ` (${recentCoupon.code})`;
                }
            } else if (code) {
                polished = `Update coupon code ${code}`;
            } else {
                polished = 'Update the existing coupon';
            }
        } else {
            polished = 'Create ';
            polished += code ? `a coupon code ${code}` : 'a coupon';
        }

        if (amount) {
            const amountText = type === 'percent' ? `${amount}% discount` : `${amount} fixed cart discount`;
            polished += isUpdateIntent ? ` and set discount to ${amountText}` : ` with ${amountText}`;
        } else {
            polished += isUpdateIntent ? ' with the requested changes' : ' with a clear discount amount';
        }

        const restrictions = [];
        if (formData.get('minimum_amount')) restrictions.push(`minimum spend ${formData.get('minimum_amount')}`);
        if (formData.get('maximum_amount')) restrictions.push(`maximum spend ${formData.get('maximum_amount')}`);
        if (formData.get('usage_limit')) restrictions.push(`usage limit ${formData.get('usage_limit')}`);
        if (formData.get('usage_limit_per_user')) restrictions.push(`usage limit per user ${formData.get('usage_limit_per_user')}`);
        if (formData.get('expiry_date')) restrictions.push(`expires on ${formData.get('expiry_date')}`);
        if (formData.get('free_shipping')) restrictions.push('include free shipping');
        if (formData.get('individual_use')) restrictions.push('individual use only');
        if (formData.get('exclude_sale_items')) restrictions.push('exclude sale items');

        if (restrictions.length) {
            polished += ', ' + restrictions.join(', ');
        }

        commandEl.value = polished + '.';
    }

    function getRecentGeneratedCoupon() {
        const row = document.querySelector('#zencoupon-generated-coupons-wrapper tbody tr');
        if (!row) return null;

        const codeEl = row.querySelector('.font-monospace');
        const id = row.getAttribute('data-coupon-id') || '';
        const code = codeEl ? codeEl.textContent.trim() : '';

        if (!id && !code) return null;

        return { id, code };
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
            data.append('action', 'zencoupon_ai_assistant_execute_command');
            data.append('nonce', ZenCouponAIAssistantData.nonce);
            data.append('command', command);
            data.append('restrictions', JSON.stringify(restrictions));
            
            const response = await fetch(ZenCouponAIAssistantData.ajax_url, {
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
            data.append('action', 'zencoupon_ai_assistant_refresh_generated_coupons');
            data.append('nonce', ZenCouponAIAssistantData.nonce);
            
            const response = await fetch(ZenCouponAIAssistantData.ajax_url, {
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
            data.append('action', 'zencoupon_ai_assistant_refresh_dashboard_stats');
            data.append('nonce', ZenCouponAIAssistantData.nonce);
            
            const response = await fetch(ZenCouponAIAssistantData.ajax_url, {
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
            data.append('action', 'zencoupon_ai_assistant_delete_coupon');
            data.append('nonce', ZenCouponAIAssistantData.nonce);
            data.append('coupon_id', couponId);
            
            const response = await fetch(ZenCouponAIAssistantData.ajax_url, {
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

    async function sendSupportRequest(e) {
        e.preventDefault();

        const form = document.getElementById('zencoupon-support-form');
        const resultEl = document.getElementById('zencoupon-support-result');
        if (!form || !resultEl) return;

        const data = new FormData(form);
        data.append('action', 'zencoupon_ai_assistant_send_support');
        data.append('nonce', ZenCouponAIAssistantData.nonce);

        resultEl.textContent = 'Sending...';
        resultEl.className = 'small text-muted mt-3';

        try {
            const response = await fetch(ZenCouponAIAssistantData.ajax_url, {
                method: 'POST',
                body: data
            });
            const result = await response.json();

            resultEl.textContent = result.data?.message || (result.success ? 'Support request sent.' : 'Support request failed.');
            resultEl.className = result.success ? 'small text-success mt-3' : 'small text-danger mt-3';

            if (result.success) {
                form.reset();
            }
        } catch (error) {
            resultEl.textContent = 'Error: ' + error.message;
            resultEl.className = 'small text-danger mt-3';
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
