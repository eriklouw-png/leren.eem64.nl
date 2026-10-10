(function(){
'use strict';

function getModal(button){
    const id=button.dataset.listModal;
    if(!id)return null;
    return document.getElementById(id);
}

function parseActions(button){
    try{
        const raw=button.dataset.listActions||'[]';
        const actions=JSON.parse(raw);
        return Array.isArray(actions)?actions:[];
    }catch(error){
        console.error('Leren: menu-acties konden niet worden gelezen.',error);
        return [];
    }
}

function addLinkAction(actions,action){
    if(!action || !action.label || !action.href)return;
    const link=document.createElement('a');
    link.className='leren-modal-action'+(action.primary?' primary':'')+(action.class?' '+action.class:'');
    link.href=action.href;
    if(action.confirm)link.dataset.confirm=action.confirm;
    link.textContent=action.label;
    actions.appendChild(link);
}

function addFormAction(actions,action){
    if(!action || !action.label || !action.action)return;
    const form=document.createElement('form');
    form.method=(action.method||'post').toLowerCase();
    form.action=action.action;
    if(action.confirm)form.dataset.confirm=action.confirm;

    const fields=action.fields && typeof action.fields==='object' ? action.fields : {};
    Object.keys(fields).forEach(function(name){
        const input=document.createElement('input');
        input.type='hidden';
        input.name=name;
        input.value=String(fields[name]);
        form.appendChild(input);
    });

    const button=document.createElement('button');
    button.type='submit';
    button.className='leren-modal-action'+(action.primary?' primary':'')+(action.danger?' danger':'');
    button.textContent=action.label;
    form.appendChild(button);
    actions.appendChild(form);
}

function openListModal(button){
    const modal=getModal(button);
    if(!modal)return;

    const title=modal.querySelector('[data-list-modal-title]');
    const actions=modal.querySelector('[data-list-modal-actions]');
    if(!actions)return;

    modal._lerenLastFocus=button;
    if(title)title.textContent=button.dataset.menuTitle||'Opties';
    actions.innerHTML='';

    parseActions(button).forEach(function(action){
        if(action.type==='form')addFormAction(actions,action);
        else addLinkAction(actions,action);
    });

    modal.hidden=false;
    modal.setAttribute('aria-hidden','false');
    document.body.classList.add('leren-modal-open');

    const close=modal.querySelector('button[data-list-modal-close]');
    if(close)close.focus();
}

function closeListModal(modal){
    if(!modal)return;
    modal.hidden=true;
    modal.setAttribute('aria-hidden','true');
    document.body.classList.remove('leren-modal-open');
    if(modal._lerenLastFocus && typeof modal._lerenLastFocus.focus==='function'){
        modal._lerenLastFocus.focus();
    }
}

function initListMenus(){
    document.addEventListener('click',function(event){
        const menuButton=event.target.closest('[data-list-menu]');
        if(menuButton){
            event.preventDefault();
            event.stopPropagation();
            openListModal(menuButton);
            return;
        }

        const closeButton=event.target.closest('[data-list-modal-close]');
        if(closeButton){
            const modal=closeButton.closest('.leren-modal');
            closeListModal(modal);
        }
    });

    document.addEventListener('keydown',function(event){
        if(event.key!=='Escape')return;
        document.querySelectorAll('.leren-modal:not([hidden])').forEach(function(modal){
            if(modal.hasAttribute('data-list-modal'))closeListModal(modal);
        });
    });
}

function initConfirmModal(){
    const modal=document.getElementById('lerenConfirmModal');
    if(!modal)return;

    const message=document.getElementById('lerenConfirmMessage');
    const ok=modal.querySelector('[data-confirm-ok]');
    const restartClass='leren-menu-restart';
    let pendingForm=null;
    let pendingSubmitter=null;
    let pendingLinkHref=null;

    function close(){
        modal.hidden=true;
        modal.setAttribute('aria-hidden','true');
        document.body.classList.remove('leren-modal-open');
        pendingForm=null;
        pendingSubmitter=null;
        pendingLinkHref=null;
    }

    function setConfirmButton(isRestart){
        if(!ok)return;
        ok.textContent=isRestart?'Start opnieuw':'Verwijderen';
        ok.classList.toggle('leren-confirm-start',isRestart);
        ok.classList.toggle('leren-confirm-delete',!isRestart);
    }

    function open(form,submitter){
        pendingForm=form;
        pendingSubmitter=submitter||null;
        if(message)message.textContent=form.dataset.confirm||'Weet u zeker dat u dit wilt verwijderen?';
        setConfirmButton(form.classList.contains(restartClass));
        modal.hidden=false;
        modal.setAttribute('aria-hidden','false');
        document.body.classList.add('leren-modal-open');
        if(ok)ok.focus();
    }

    document.addEventListener('submit',function(event){
        const form=event.target;
        if(!(form instanceof HTMLFormElement)||!form.dataset.confirm||form.dataset.confirmed==='1')return;
        event.preventDefault();
        open(form,event.submitter);
    },true);

    document.addEventListener('click',function(event){
        const link=event.target.closest('a[data-confirm]');
        if(!link || link.dataset.confirmed==='1')return;
        event.preventDefault();
        pendingForm=null;
        pendingSubmitter=null;
        pendingLinkHref=link.href;
        if(message)message.textContent=link.dataset.confirm;
        setConfirmButton(link.classList.contains(restartClass));
        modal.hidden=false;
        modal.setAttribute('aria-hidden','false');
        document.body.classList.add('leren-modal-open');
        if(ok)ok.focus();
    },true);

    if(ok){
        ok.addEventListener('click',function(){
            if(!pendingForm && !pendingLinkHref)return;
            const form=pendingForm;
            const submitter=pendingSubmitter;
            const linkHref=pendingLinkHref;
            close();
            if(linkHref){
                window.location.href=linkHref;
                return;
            }
            if(!form)return;
            form.dataset.confirmed='1';
            if(submitter && typeof form.requestSubmit==='function')form.requestSubmit(submitter);
            else form.submit();
        });
    }

    modal.addEventListener('click',function(event){
        if(event.target.closest('[data-confirm-close],[data-confirm-cancel]'))close();
    });

    document.addEventListener('keydown',function(event){
        if(event.key==='Escape' && !modal.hidden)close();
    });
}

function initFormValidation(){
    document.querySelectorAll('form').forEach(function(form){
        form.addEventListener('invalid',function(event){
            const field=event.target;
            if(!(field instanceof HTMLElement))return;
            field.classList.add('is-invalid');
            let feedback=field.parentElement.querySelector('.leren-field-error');
            if(!feedback){feedback=document.createElement('div');feedback.className='leren-field-error invalid-feedback d-block text-danger';field.insertAdjacentElement('afterend',feedback);}
            feedback.textContent=field.validity && field.validity.valueMissing?'Dit veld is verplicht.':'Controleer de ingevulde waarde.';
        },true);
        form.addEventListener('input',function(event){
            const field=event.target;
            if(field instanceof HTMLElement && typeof field.checkValidity==='function' && field.checkValidity()){
                field.classList.remove('is-invalid');
                const feedback=field.parentElement.querySelector('.leren-field-error');
                if(feedback)feedback.remove();
            }
        });
    });
}

function initProgressBars(){
    document.querySelectorAll('.progress-bar').forEach(function(bar){
        const progress=bar.closest('.progress');
        if(!progress)return;
        let value=Number(progress.getAttribute('aria-valuenow'));
        if(!Number.isFinite(value)){
            const width=String(bar.style.width||'').replace('%','');
            value=Number(width);
        }
        if(!Number.isFinite(value))return;
        value=Math.max(0,Math.min(100,value));
        bar.classList.remove('leren-progress-red','leren-progress-orange','leren-progress-green');
        if(value<=24)bar.classList.add('leren-progress-red');
        else if(value<=74)bar.classList.add('leren-progress-orange');
        else bar.classList.add('leren-progress-green');
    });
}

document.addEventListener('DOMContentLoaded',function(){
    initListMenus();
    initConfirmModal();
    initProgressBars();
    initFormValidation();
});
})();