import * as auth from './generated/routes/api/v1/auth';
import * as verification from './generated/routes/api/v1/auth/verification';
import { me } from './generated/routes/api/v1';
import * as profile from './generated/routes/api/v1/me';
import * as services from './generated/routes/api/v1/services';
import * as orders from './generated/routes/api/v1/orders';
import * as messages from './generated/routes/api/v1/orders/messages';
import * as status from './generated/routes/api/v1/orders/status';
import * as reviews from './generated/routes/api/v1/orders/reviews';
import * as assistant from './generated/routes/api/v1/assistant/messages';
import * as assistantActions from './generated/routes/api/v1/assistant/actions';
import * as inventory from './generated/routes/api/v1/inventory';
import { performance } from './generated/routes/api/v1/technician';

export const endpoints = {
    auth,
    verification,
    me,
    profile,
    services,
    orders,
    messages,
    status,
    reviews,
    assistant,
    assistantActions,
    inventory,
    performance,
};
