/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

import $ from 'jquery';
import { AjaxResponse } from '@typo3/core/ajax/ajax-response';
import { AbstractInteractableModule, ModuleLoadedResponseWithButtons } from '../abstract-interactable-module';
import Modal from '@typo3/backend/modal';
import Notification from '@typo3/backend/notification';
import AjaxRequest from '@typo3/core/ajax/ajax-request';
import InfoBox from '../../renderable/info-box';
import ProgressBar from '../../renderable/progress-bar';
import Severity from '../../renderable/severity';
import Router from '../../router';
import MessageInterface from '@typo3/install/message-interface';

type TcaCheckResponse = ModuleLoadedResponseWithButtons & {
  status: MessageInterface[]
};

/**
 * Module: @typo3/install/module/tca-ext-tables-check
 */
class TcaExtTablesCheck extends AbstractInteractableModule {
  private readonly selectorCheckTrigger: string = '.t3js-tcaExtTablesCheck-check';
  private readonly selectorOutputContainer: string = '.t3js-tcaExtTablesCheck-output';

  public initialize(currentModal: JQuery): void {
    this.currentModal = currentModal;
    this.check();
    currentModal.on('click', this.selectorCheckTrigger, (e: JQueryEventObject): void => {
      e.preventDefault();
      this.check();
    });
  }

  private check(): void {
    this.setModalButtonsState(false);

    const modalContent = this.getModalBody();
    const $outputContainer = $(this.selectorOutputContainer);
    const m: JQuery = ProgressBar.render(Severity.loading, 'Loading...', '');
    $outputContainer.empty().append(m);
    (new AjaxRequest(Router.getUrl('tcaExtTablesCheck')))
      .get({ cache: 'no-cache' })
      .then(
        async (response: AjaxResponse): Promise<void> => {
          const data: TcaCheckResponse = await response.resolve();
          modalContent.empty().append(data.html);
          Modal.setButtons(data.buttons);
          if (data.success === true && Array.isArray(data.status)) {
            if (data.status.length > 0) {
              const aMessage = InfoBox.render(
                Severity.warning,
                'Following extensions change TCA in ext_tables.php',
                'Check ext_tables.php files, look for ExtensionManagementUtility calls and $GLOBALS[\'TCA\'] modifications',
              );
              modalContent.find(this.selectorOutputContainer).append(aMessage);
              data.status.forEach((element: MessageInterface): void => {
                const m2 = InfoBox.render(element.severity, element.title, element.message);
                $outputContainer.append(m2);
                modalContent.append(m2);
              });
            } else {
              const aMessage = InfoBox.render(Severity.ok, 'No TCA changes in ext_tables.php files. Good job!', '');
              modalContent.find(this.selectorOutputContainer).append(aMessage);
            }
          } else {
            Notification.error('Something went wrong', 'Please use the module "Check for broken extensions" to find a possible extension causing this issue.');
          }
        },
        (error: AjaxResponse): void => {
          Router.handleAjaxError(error, modalContent);
        }
      ).finally((): void => {
        this.setModalButtonsState(true);
      });
  }
}

export default new TcaExtTablesCheck();
